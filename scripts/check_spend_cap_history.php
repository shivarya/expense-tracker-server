<?php
/**
 * Assertion checks for which cap a past month is judged against — pure logic, no MySQL.
 * Run after touching utils/spendCapHistory.php:
 *
 *   php scripts/check_spend_cap_history.php
 */

require_once __DIR__ . '/../utils/spendCapHistory.php';

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}

$goal = ['target_amount' => '60000.00', 'excluded_category_ids' => [9]];
// Created in August at 40K, raised to 50K in September (excluding category 4), raised again to 60K in October.
$rows = [
    ['month' => '2026-10', 'target_amount' => '60000.00', 'excluded_category_ids' => '[9]'],
    ['month' => '2026-08', 'target_amount' => '40000.00', 'excluded_category_ids' => null],
    ['month' => '2026-09', 'target_amount' => '50000.00', 'excluded_category_ids' => '[4]'],
];

$at = fn(string $month) => resolveSpendCapTerms($rows, $month, $goal);

check('a month with its own row uses it', $at('2026-09')['target_amount'] === 50000.0);
check('its exclusions come along', $at('2026-09')['excluded_category_ids'] === [4]);
check('rows arrive in any order', $at('2026-08')['target_amount'] === 40000.0);
check('the current month uses its row', $at('2026-10')['target_amount'] === 60000.0);
check('no exclusions stays null', $at('2026-08')['excluded_category_ids'] === null);
check('a month before the first row uses the first row', $at('2026-05')['target_amount'] === 40000.0);
check('a month between rows uses the earlier row', resolveSpendCapTerms([$rows[1], $rows[0]], '2026-09', $goal)['target_amount'] === 40000.0);
check('a month after the last row uses the last row', $at('2026-12')['target_amount'] === 60000.0);
check('no history falls back to the goal', resolveSpendCapTerms([], '2026-07', $goal) === ['target_amount' => 60000.0, 'excluded_category_ids' => [9]]);
check('a goal row with JSON exclusions decodes them', resolveSpendCapTerms([], '2026-07', ['target_amount' => 1, 'excluded_category_ids' => '[3,"5"]'])['excluded_category_ids'] === [3, 5]);
check('id lists accept arrays, JSON and nothing', spendCapIdList(['7']) === [7] && spendCapIdList('[]') === [] && spendCapIdList(null) === null);
check('six months in total', SPEND_CAP_HISTORY_MONTHS === 6);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
