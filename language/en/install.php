<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

$lang = array_merge($lang ?? [], [
	'TITANIA_TRANSFER_NOT_ENABLEABLE' => 'Titania Topic Transfer requires phpBB 4.0.x and PHP 8.2 or newer.',
]);
