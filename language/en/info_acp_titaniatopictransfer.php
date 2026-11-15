<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

// Moderator log entry, shown in the ACP moderator log (info_mcp_ has the same key for the MCP)
$lang = array_merge($lang, [
	'LOG_TITANIATOPICTRANSFER'	=> '<strong>Transferred posts to Titania support</strong><br>» from “%1$s” to %2$s',
]);
