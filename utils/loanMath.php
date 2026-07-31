<?php
// Pure loan-amortization math for debt_payoff goals. No DB access --
// unit-testable directly against a known amortization table.

// Standard EMI formula: EMI = P.r.(1+r)^n / ((1+r)^n - 1)
function calculateRequiredEmi(float $principal, float $annualRatePercent, int $months): float
{
  if ($months <= 0) return $principal; // degenerate: due now
  $r = $annualRatePercent / 12 / 100;
  if ($r == 0.0) return $principal / $months;
  $factor = pow(1 + $r, $months);
  return $principal * $r * $factor / ($factor - 1);
}

// Lumpsum prepayment needed to clear the loan in $targetMonths while
// keeping the EMI unchanged.
// P_needed = EMI . [(1+r)^n - 1] / [r.(1+r)^n]
// lumpsum  = $principal - P_needed
// Returns a positive number if a prepayment is needed; 0 or negative means
// the current EMI already clears the loan within $targetMonths unaided
// (caller should clamp negative to 0 and surface "already on track").
function calculatePrepaymentForTargetTenure(float $principal, float $emi, float $annualRatePercent, int $targetMonths): float
{
  if ($targetMonths <= 0) return $principal;
  $r = $annualRatePercent / 12 / 100;
  if ($r == 0.0) {
    $pNeeded = $emi * $targetMonths;
  } else {
    $factor = pow(1 + $r, $targetMonths);
    $pNeeded = $emi * ($factor - 1) / ($r * $factor);
  }
  return $principal - $pNeeded;
}
