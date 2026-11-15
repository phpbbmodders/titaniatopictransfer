<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\titaniatopictransfer\service;

use phpbb\db\driver\driver_interface;
use phpbb\exception\runtime_exception;
use phpbb\storage\storage;
use phpbb\titania\ext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Copy forum attachments to Titania and verify the copied bytes.
 *
 * Titania's services exist only while Titania is enabled, so they are fetched
 * from the container when a transfer runs instead of being injected.
 */
final class attachments
{
	/** @var list<string> Files written during this transfer, removed again by rollback() */
	private array $created = [];

	public function __construct(
		private driver_interface $db,
		private storage $storage,
		private ContainerInterface $container
	)
	{
	}

	/**
	 * Copy every attachment of a forum post to a Titania support post.
	 *
	 * @param int $source_id      Forum post ID
	 * @param int $destination_id Titania post ID
	 * @throws runtime_exception When a record is missing or Titania refuses the file type
	 * @throws \RuntimeException When a file cannot be copied or registered
	 */
	public function copy(int $source_id, int $destination_id): void
	{
		$titania_config = $this->container->get('phpbb.titania.config');

		$sql = 'SELECT physical_filename, real_filename, attach_comment, extension, mimetype, filesize, filetime, thumbnail, poster_id
			FROM ' . ATTACHMENTS_TABLE . '
			WHERE post_msg_id = ' . (int) $source_id . '
				AND in_message = 0
				AND is_orphan = 0
			ORDER BY filetime DESC, attach_id DESC';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (!$rows)
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_ATTACHMENT_MISSING');
		}

		$allowed = $titania_config->upload_allowed_extensions[ext::TITANIA_SUPPORT] ?? [];
		foreach ($rows as $order => $row)
		{
			$source_name = $row['physical_filename'];
			if ($source_name === '' || $source_name !== basename($source_name) || !$row['extension']
				|| !in_array(strtolower($row['extension']), $allowed, true))
			{
				throw new runtime_exception('TITANIATOPICTRANSFER_ERR_ATTACHMENT_TYPE');
			}

			$attachment = $this->container->get('phpbb.titania.attachment');
			$attachment->__set_array([
				'object_type'			=> ext::TITANIA_SUPPORT,
				'object_id'				=> $destination_id,
				'attachment_user_id'	=> (int) $row['poster_id'],
				'attachment_directory'	=> 'support',
				'physical_filename'		=> bin2hex(random_bytes(20)),
				'real_filename'			=> $row['real_filename'],
				'attachment_comment'	=> $row['attach_comment'],
				'filesize'				=> (int) $row['filesize'],
				'filetime'				=> (int) $row['filetime'],
				'extension'				=> $row['extension'],
				'mimetype'				=> $row['mimetype'],
				'thumbnail'				=> (int) $row['thumbnail'],
				'attachment_order'		=> $order,
				'is_orphan'				=> 0,
			]);
			$this->copy_file($source_name, $attachment->get_filepath());
			$attachment->set('hash', md5_file($attachment->get_filepath()));
			if ($row['thumbnail'])
			{
				$this->copy_file('thumb_' . $source_name, $attachment->get_filepath(true));
			}

			$attachment->submit();
			if (!$attachment->get_id())
			{
				throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
			}
		}
	}

	/**
	 * Remove the files this transfer wrote, after the database work was rolled back.
	 */
	public function rollback(): void
	{
		foreach ($this->created as $path)
		{
			if (is_file($path))
			{
				unlink($path);
			}
		}
		$this->created = [];
	}

	/**
	 * Copy one file out of phpBB's attachment storage and check its size.
	 *
	 * @param string $source      Name in phpBB's attachment storage
	 * @param string $destination Path in Titania's files folder; must not exist yet
	 * @throws \RuntimeException When the file cannot be written or the sizes differ
	 */
	private function copy_file(string $source, string $destination): void
	{
		$input = $this->storage->read($source);
		try
		{
			$output = fopen($destination, 'xb');
			if ($output === false)
			{
				throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
			}

			$this->created[] = $destination;
			try
			{
				$bytes = stream_copy_to_stream($input, $output);
			}
			finally
			{
				fclose($output);
			}

			clearstatcache(true, $destination);
			if ($bytes === false || $bytes !== $this->storage->file_size($source) || filesize($destination) !== $bytes)
			{
				throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
			}
		}
		finally
		{
			fclose($input);
		}
	}
}
