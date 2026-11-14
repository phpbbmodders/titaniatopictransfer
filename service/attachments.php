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

use phpbb\titania\ext;

/** Copy forum attachments to Titania and verify the destination bytes. */
final class attachments
{
	private array $created = [];

	public function copy(int $source_id, int $destination_id): void
	{
		$db = \phpbb::$db;
		$container = \phpbb::$container;
		$storage = $container->get('storage.attachment');
		$titania_config = $container->get('phpbb.titania.config');
		$result = $db->sql_query('SELECT * FROM ' . ATTACHMENTS_TABLE . '
			WHERE post_msg_id = ' . $source_id . ' AND in_message = 0 AND is_orphan = 0
			ORDER BY filetime DESC, attach_id DESC');
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		if (!$rows)
		{
			throw new \DomainException('The source attachment records are missing.');
		}

		foreach ($rows as $order => $row)
		{
			$source_name = $row['physical_filename'];
			if ($source_name !== basename($source_name) || $source_name === '' || !$row['extension']
				|| !in_array(strtolower($row['extension']), $titania_config->upload_allowed_extensions[ext::TITANIA_SUPPORT], true))
			{
				throw new \DomainException('Titania does not accept one of the selected attachments.');
			}
			$attachment = $container->get('phpbb.titania.attachment');
			$attachment->__set_array([
				'object_type' => ext::TITANIA_SUPPORT,
				'object_id' => $destination_id,
				'attachment_user_id' => (int) $row['poster_id'],
				'attachment_directory' => 'support',
				'physical_filename' => bin2hex(random_bytes(20)),
				'real_filename' => $row['real_filename'],
				'attachment_comment' => $row['attach_comment'],
				'filesize' => (int) $row['filesize'],
				'filetime' => (int) $row['filetime'],
				'extension' => $row['extension'],
				'mimetype' => $row['mimetype'],
				'thumbnail' => (int) $row['thumbnail'],
				'attachment_order' => $order,
				'is_orphan' => 0,
			]);
			$this->copy_file($storage, $source_name, $attachment->get_filepath());
			$attachment->set('hash', md5_file($attachment->get_filepath()));
			if ($row['thumbnail'])
			{
				$this->copy_file($storage, 'thumb_' . $source_name, $attachment->get_filepath(true));
			}
			$attachment->submit();
			if (!$attachment->get_id())
			{
				throw new \RuntimeException('Titania could not register the attachment.');
			}
		}
	}

	public function rollback(): void
	{
		foreach ($this->created as $path)
		{
			unlink($path);
		}
	}

	private function copy_file(\phpbb\storage\storage $storage, string $source, string $destination): void
	{
		$input = $storage->read($source);
		try
		{
			$output = fopen($destination, 'xb');
			if ($output === false)
			{
				throw new \RuntimeException('Unable to create the destination attachment.');
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

			if ($bytes === false || $bytes !== $storage->file_size($source) || filesize($destination) !== $bytes)
			{
				throw new \RuntimeException('The copied attachment failed verification.');
			}
		}
		finally
		{
			fclose($input);
		}
	}
}
