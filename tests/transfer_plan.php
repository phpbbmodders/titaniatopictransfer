<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

require __DIR__ . '/../service/transfer_plan.php';

use phpbbmodders\titaniatopictransfer\service\transfer_plan;

$planner = new transfer_plan();
$cases = [
	'whole move' => [[30, 10, 20], false, [10, 20, 30], true, false],
	'whole copy' => [[10, 20, 30], true, [], false, true],
	'opening post only' => [[10], false, [], false, true],
	'opening post and reply' => [[30, 10], false, [30], false, true],
	'reply only' => [[20, 20], false, [20], false, false],
];

foreach ($cases as $name => [$selection, $copy, $remove, $shadow, $close])
{
	$result = $planner->build([10, 20, 30], $selection, $copy);
	$expected = [
		'transfer' => array_values(array_intersect([10, 20, 30], $selection)),
		'remove' => $remove,
		'shadow' => $shadow,
		'close' => $close,
	];

	if ($result !== $expected)
	{
		throw new RuntimeException("Unexpected plan for $name");
	}
}

foreach ([[[], false], [[99], false], [[10], true]] as [$selection, $copy])
{
	try
	{
		$planner->build([10, 20, 30], $selection, $copy);
		throw new RuntimeException('The planner accepted an invalid selection.');
	}
	catch (InvalidArgumentException)
	{
	}
}

echo "Transfer plan checks passed.\n";
