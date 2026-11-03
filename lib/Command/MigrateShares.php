<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\ShardingService;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recreate the shares of a migration list (shares.json from the export) whose
 * owner lives on this node, after the owners' files have been copied and scanned:
 *
 * - links keep their token (papers cite them), password hash, expiry and date;
 * - a user share becomes a local share when the recipient lives here too, and
 *   otherwise a federated share to recipient@master, as the share dialog makes it;
 * - a group share becomes a group share (user_group_admin replicates the group,
 *   files_sharding fans it out to members on other nodes).
 *
 * A shared item that is no longer at its old path is looked for by name in the
 * owner's files and used only if exactly one item of that name and kind exists
 * (the old move/rename hooks did not always keep the shares up to date).
 * Everything else that cannot be made is skipped and listed in the report.
 * Safe to re-run: a share that is already there is left alone.
 */
class MigrateShares extends Command {
	private const LINK_MASK_FOLDER = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE
		| Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE;
	private const FILE_MASK = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE;

	public function __construct(
		private ShardingService $shardingService,
		private IShareManager   $shareManager,
		private IRootFolder     $rootFolder,
		private IUserManager    $userManager,
		private IGroupManager   $groupManager,
		private IDBConnection   $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:migrate:shares')
			->setDescription('Recreate the shares of a migration list for the owners who live on this node')
			->addArgument('file', InputArgument::REQUIRED, 'shares.json from the export')
			->addOption('hashes', null, InputOption::VALUE_REQUIRED, 'Tab-separated "share:<old id>" and link password hash')
			->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write the skipped and relocated shares here (TSV)')
			->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated owner uids: only their shares')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check paths and report, create nothing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$list = json_decode((string)file_get_contents((string)$input->getArgument('file')), true);
		if (!is_array($list)) {
			$output->writeln('<error>Cannot read the list.</error>');
			return 1;
		}
		$hashes = [];
		if ($input->getOption('hashes')) {
			foreach (file((string)$input->getOption('hashes'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
				[$key, $hash] = array_pad(explode("\t", $line, 2), 2, '');
				if (str_starts_with($key, 'share:') && $hash !== '') {
					$hashes[(int)substr($key, 6)] = $hash;
				}
			}
		}
		$only = $input->getOption('only') ? array_flip(explode(',', (string)$input->getOption('only'))) : null;
		$dry = (bool)$input->getOption('dry-run');
		$masterHost = preg_replace('#^https?://#', '', rtrim($this->shardingService->masterUrl(), '/'));

		$report = [];
		$count = ['created' => 0, 'there' => 0, 'skipped' => 0];
		foreach ($list as $entry) {
			$owner = (string)$entry['owner'];
			if (!$this->shardingService->isThisNode((string)$entry['owner_home'])
				|| ($only !== null && !isset($only[$owner]))) {
				continue;
			}
			$id = (int)$entry['id'];
			$skip = function (string $why) use (&$report, &$count, $entry, $id): void {
				$report[] = [$id, $entry['type'], $entry['owner'], $entry['path'], $entry['share_with'] ?? $entry['token'] ?? '', 'skipped: ' . $why];
				$count['skipped']++;
			};

			$user = $this->userManager->get($owner);
			if ($user === null || $user->getUID() !== $owner) {
				$skip('owner has no account here');
				continue;
			}
			$node = $this->locate($owner, (string)$entry['path'], (string)$entry['item_type'], $relocated);
			if ($node === null) {
				$skip($relocated === null ? 'not found' : "not found; {$relocated} items of that name");
				continue;
			}
			if ($relocated !== null) {
				$report[] = [$id, $entry['type'], $owner, $entry['path'], $entry['share_with'] ?? $entry['token'] ?? '', 'relocated to ' . $relocated];
			}
			$expiration = $this->expiration((string)($entry['expiration'] ?? ''));
			if ($expiration !== null && $expiration < new \DateTime()) {
				$skip('expired ' . $expiration->format('Y-m-d'));
				continue;
			}

			try {
				switch ((int)$entry['type']) {
					case 3:
						$done = $this->link($entry, $node, $expiration, $hashes[$id] ?? null, $dry, $output);
						break;
					case 0:
						$local = $this->shardingService->isThisNode((string)$entry['recipient_home']);
						$done = $this->share($entry, $node, $expiration, $local ? IShare::TYPE_USER : IShare::TYPE_REMOTE,
							$local ? (string)$entry['share_with'] : $entry['share_with'] . '@' . $masterHost, $dry);
						break;
					case 1:
						if (!$this->groupManager->groupExists((string)$entry['share_with'])) {
							$skip('no group ' . $entry['share_with'] . ' here');
							continue 2;
						}
						$done = $this->share($entry, $node, $expiration, IShare::TYPE_GROUP, (string)$entry['share_with'], $dry);
						break;
					default:
						$skip('share type ' . $entry['type']);
						continue 2;
				}
				$count[$done ? 'created' : 'there']++;
			} catch (\Throwable $e) {
				$skip($e->getMessage());
			}
		}

		if ($input->getOption('report')) {
			$f = fopen((string)$input->getOption('report'), 'w');
			fwrite($f, "old_id\ttype\towner\tpath\twith_or_token\toutcome\n");
			foreach ($report as $row) {
				fwrite($f, implode("\t", array_map(fn ($v) => str_replace(["\t", "\n"], ' ', (string)$v), $row)) . "\n");
			}
			fclose($f);
		}
		$output->writeln(sprintf('%s %d, already there %d, skipped %d', $dry ? 'would create' : 'created',
			$count['created'], $count['there'], $count['skipped']));
		return 0;
	}

	/**
	 * The shared item at its old path, or else the only item of that name and kind
	 * in the owner's files. $relocated: null if found at the path; else the new
	 * path, or the number of candidates when there is not exactly one.
	 */
	private function locate(string $owner, string $path, string $itemType, ?string &$relocated): ?Node {
		$relocated = null;
		$userFolder = $this->rootFolder->getUserFolder($owner);
		$wantFolder = $itemType === 'folder';
		try {
			$node = $userFolder->get($path);
			if (($node instanceof Folder) === $wantFolder) {
				return $node;
			}
		} catch (NotFoundException) {
		}
		$name = basename($path);
		$matches = array_values(array_filter($userFolder->search($name),
			fn (Node $n) => $n->getName() === $name && ($n instanceof Folder) === $wantFolder));
		if (count($matches) !== 1) {
			$relocated = (string)count($matches);
			return null;
		}
		$relocated = $userFolder->getRelativePath($matches[0]->getPath());
		return $matches[0];
	}

	private function expiration(string $value): ?\DateTime {
		if ($value === '' || str_starts_with($value, '0000')) {
			return null;
		}
		try {
			return new \DateTime($value);
		} catch (\Exception) {
			return null;
		}
	}

	private function permissions(array $entry, Node $node, bool $link): int {
		$perm = (int)$entry['permissions'];
		if (!($node instanceof Folder)) {
			return ($perm & ($link ? Constants::PERMISSION_READ : self::FILE_MASK)) | Constants::PERMISSION_READ;
		}
		return ($perm & ($link ? self::LINK_MASK_FOLDER : Constants::PERMISSION_ALL)) | Constants::PERMISSION_READ;
	}

	/** @return bool true if made now, false if it was already there */
	private function link(array $entry, Node $node, ?\DateTime $expiration, ?string $hash, bool $dry, OutputInterface $output): bool {
		$token = (string)$entry['token'];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'uid_owner', 'file_source')->from('share')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		if ($row !== false) {
			if ((string)$row['uid_owner'] !== (string)$entry['owner'] || (int)$row['file_source'] !== $node->getId()) {
				throw new \RuntimeException("token {$token} is already used by another share");
			}
			return false;
		}
		if (!empty($entry['has_password']) && $hash === null) {
			$output->writeln("<comment>link {$entry['id']} ({$token}) had a password, but no hash was given: made WITHOUT one is not safe - skipped</comment>");
			throw new \RuntimeException('password hash missing');
		}
		if ($dry) {
			return true;
		}
		$share = $this->shareManager->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setSharedBy((string)$entry['owner'])
			->setPermissions($this->permissions($entry, $node, true));
		$share = $this->shareManager->createShare($share);

		// Core always draws a new token and hashes a plaintext password; the old
		// token and hash go into the row afterwards.
		$qb = $this->db->getQueryBuilder();
		$qb->update('share')
			->set('token', $qb->createNamedParameter($token))
			->set('stime', $qb->createNamedParameter((int)$entry['stime'] ?: time()))
			->set('expiration', $qb->createNamedParameter($expiration, $expiration === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_DATE))
			->set('password', $qb->createNamedParameter($hash, $hash === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$share->getId())));
		$qb->executeStatement();
		return true;
	}

	/** @return bool true if made now, false if it was already there */
	private function share(array $entry, Node $node, ?\DateTime $expiration, int $type, string $with, bool $dry): bool {
		foreach ($this->shareManager->getSharesBy((string)$entry['owner'], $type, $node, false, -1) as $existing) {
			if ($existing->getSharedWith() === $with) {
				return false;
			}
		}
		if ($dry) {
			return true;
		}
		$share = $this->shareManager->newShare();
		$share->setNode($node)
			->setShareType($type)
			->setSharedBy((string)$entry['owner'])
			->setSharedWith($with)
			->setPermissions($this->permissions($entry, $node, false));
		if ($expiration !== null) {
			$share->setExpirationDate($expiration);
		}
		$this->shareManager->createShare($share);
		return true;
	}
}
