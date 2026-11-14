<?php

namespace phpbbmodders\titaniatopictransfer\service;

/**
 * Avoid Titania's duplicate posted-marker insert inside a transfer transaction.
 */
final class support_topic extends \titania_topic
{
	public function update_posted_status($mode = 'add', $user_id = false)
	{
		if ($mode === 'add')
		{
			$db = \phpbb::$db;
			$result = $db->sql_query('SELECT topic_posted FROM ' . TITANIA_TOPICS_POSTED_TABLE . '
				WHERE topic_id = ' . (int) $this->topic_id . ' AND user_id = ' . (int) $user_id);
			$posted = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if ($posted)
			{
				return;
			}
		}

		parent::update_posted_status($mode, $user_id);
	}
}
