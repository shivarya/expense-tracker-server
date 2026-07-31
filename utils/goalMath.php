<?php
// Generic finance math shared by debt_payoff (n-months input), savings
// (months remaining), and net_worth (projection). Kept separate from
// loanMath.php since it isn't loan-specific.

// Whole months from today to $toDate, rounding a partial trailing month up
// (so a target 40 days out is treated as 2 months, never truncated to 1 and
// silently missed). Returns 0 if $toDate is today or in the past.
function monthsBetweenDates(string $toDate, ?string $fromDate = null): int
{
  $from = new DateTime($fromDate ?? 'today');
  $to = new DateTime($toDate);
  if ($to <= $from) return 0;
  $diff = $from->diff($to);
  $months = $diff->y * 12 + $diff->m;
  if ($diff->d > 0) $months += 1;
  return max($months, 0);
}

// Compound future value with a level monthly contribution:
// FV = PV.(1+r)^t + PMT.[((1+r)^t - 1)/r]
function calculateFutureValue(float $presentValue, float $monthlyContribution, float $annualRatePercent, int $months): float
{
  if ($months <= 0) return $presentValue;
  $r = $annualRatePercent / 12 / 100;
  if ($r == 0.0) return $presentValue + $monthlyContribution * $months;
  $growth = pow(1 + $r, $months);
  return $presentValue * $growth + $monthlyContribution * (($growth - 1) / $r);
}

// Inverse of calculateFutureValue -- solves for the level monthly
// contribution (PMT) needed so the present value grows to $targetFutureValue
// by $months from now at $annualRatePercent:
//   PMT = (FV - PV.(1+r)^t) . r / ((1+r)^t - 1)
// Returns 0 (not negative) if the present value alone is already projected
// to clear the target -- caller should treat 0 as "on track without extra
// investment", not "nothing to invest at all".
function calculateRequiredMonthlyContribution(float $presentValue, float $targetFutureValue, float $annualRatePercent, int $months): float
{
  if ($months <= 0) return max(0, $targetFutureValue - $presentValue);
  $r = $annualRatePercent / 12 / 100;
  if ($r == 0.0) return max(0, ($targetFutureValue - $presentValue) / $months);
  $growth = pow(1 + $r, $months);
  $pmt = ($targetFutureValue - $presentValue * $growth) * $r / ($growth - 1);
  return max(0, $pmt);
}
