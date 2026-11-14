<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\titaniatopictransfer\tests;

use phpbb\exception\runtime_exception;
use phpbbmodders\titaniatopictransfer\service\transfer_plan;

/**
 * Tests what \phpbbmodders\titaniatopictransfer\service\transfer_plan decides
 * should happen to the forum topic for each kind of selection.
 */
class transfer_plan_test extends \phpbb_test_case
{
	/**
	 * @return array Selection, copy flag, then the expected transfer, remove, shadow and close
	 */
	public static function plan_data(): array
	{
		return [
			'whole topic, selected out of order'	=> [[30, 10, 20], false, [10, 20, 30], [10, 20, 30], true, false],
			'whole topic copy and close'			=> [[10, 20, 30], true, [10, 20, 30], [], false, true],
			'opening post only'						=> [[10], false, [10], [], false, true],
			'opening post and one reply'			=> [[30, 10], false, [10, 30], [30], false, true],
			'one reply, selected twice'				=> [[20, 20], false, [20], [20], false, false],
		];
	}

	/**
	 * @dataProvider plan_data
	 */
	public function test_build(array $selection, bool $copy, array $transfer, array $remove, bool $shadow, bool $close): void
	{
		$plan = (new transfer_plan())->build([10, 20, 30], $selection, $copy);

		$this->assertSame([
			'transfer'	=> $transfer,
			'remove'	=> $remove,
			'shadow'	=> $shadow,
			'close'		=> $close,
		], $plan);
	}

	/**
	 * @return array Topic posts, selection, copy flag, expected language key
	 */
	public static function invalid_data(): array
	{
		return [
			'nothing selected'				=> [[10, 20, 30], [], false, 'TITANIATOPICTRANSFER_ERR_NO_SELECTION'],
			'post from another topic'		=> [[10, 20, 30], [10, 99], false, 'TITANIATOPICTRANSFER_ERR_NO_SELECTION'],
			'topic with no visible posts'	=> [[], [10], false, 'TITANIATOPICTRANSFER_ERR_NO_SELECTION'],
			'copy without the whole topic'	=> [[10, 20, 30], [10, 20], true, 'TITANIATOPICTRANSFER_ERR_COPY_WHOLE_TOPIC'],
		];
	}

	/**
	 * @dataProvider invalid_data
	 */
	public function test_build_rejects(array $topic_posts, array $selection, bool $copy, string $key): void
	{
		$this->expectException(runtime_exception::class);
		$this->expectExceptionMessage($key);

		(new transfer_plan())->build($topic_posts, $selection, $copy);
	}
}
