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
	/**
	 * Check whether the extension can be enabled.
	 * The current phpBB and PHP version should meet or
	 * exceed the minimum version required by this extension:
	 *
	 * @return bool|string True, or the reason it cannot be enabled
	 */
	public function is_enableable()
	{
		$enableable = $this->check_phpbb_version() && $this->check_php_version();

		if (!$enableable)
		{
			$language = $this->container->get('language');
			$language->add_lang('install_titaniatopictransfer', 'phpbbmodders/titaniatopictransfer');

			return $language->lang('TITANIATOPICTRANSFER_NOT_ENABLEABLE');
		}

		return $enableable;
	}

	/**
	 * Requires phpBB 4.0. This first version targets the Titania 4.0.x branch only.
	 *
	 * @return bool
	 */
	public function check_phpbb_version()
	{
		return phpbb_version_compare(PHPBB_VERSION, '4.0.0-a1', '>=')
			&& phpbb_version_compare(PHPBB_VERSION, '4.1.0-dev', '<');
	}

	/**
	 * Requires PHP 8.2.0 or later, the same as phpBB 4.0.
	 *
	 * @return bool
	 */
	public function check_php_version()
	{
		return PHP_VERSION_ID >= 80200;
	}
}
