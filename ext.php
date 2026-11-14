<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\titaniatopictransfer;

class ext extends \phpbb\extension\base
{
	public function is_enableable()
	{
		if (PHP_VERSION_ID >= 80200 && PHPBB_VERSION !== '' && str_starts_with(PHPBB_VERSION, '4.0.'))
		{
			return true;
		}

		$language = $this->container->get('language');
		$language->add_lang('install', 'phpbbmodders/titaniatopictransfer');

		return $language->lang('TITANIA_TRANSFER_NOT_ENABLEABLE');
	}
}
