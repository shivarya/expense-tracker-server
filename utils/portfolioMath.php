<?php
// Single source of truth for "current portfolio value" -- stocks + mutual
// funds + fixed deposits + long-term funds. Used by GET /summary/balance
// AND the net_worth goal progress calc, so they can never drift apart.
function getPortfolioTotals($db, int $userId): array
{
  $stocksData = $db->fetchOne(
    "SELECT
      SUM(invested_amount) as invested,
      SUM(current_value) as total_value,
      SUM(gain_loss_amount) as gain_loss,
      AVG(gain_loss_percent) as gain_loss_percent
     FROM stocks WHERE user_id = ?",
    [$userId]
  );

  $mfData = $db->fetchOne(
    "SELECT
      SUM(invested_amount) as invested,
      SUM(current_value) as total_value,
      SUM(gain_loss_amount) as gain_loss,
      AVG(gain_loss_percent) as gain_loss_percent
     FROM mutual_funds WHERE user_id = ?",
    [$userId]
  );

  $fdData = $db->fetchOne(
    "SELECT
      SUM(principal_amount) as invested,
      SUM(maturity_value) as total_value
     FROM fixed_deposits WHERE user_id = ? AND status = 'active'",
    [$userId]
  );

  $ltfData = $db->fetchOne(
    "SELECT
      SUM(invested_amount) as invested,
      SUM(current_value) as total_value
     FROM long_term_funds WHERE user_id = ? AND status = 'active'",
    [$userId]
  );

  $totalInvested =
    ($stocksData['invested'] ?? 0) +
    ($mfData['invested'] ?? 0) +
    ($fdData['invested'] ?? 0) +
    ($ltfData['invested'] ?? 0);

  $totalValue =
    ($stocksData['total_value'] ?? 0) +
    ($mfData['total_value'] ?? 0) +
    ($fdData['total_value'] ?? 0) +
    ($ltfData['total_value'] ?? 0);

  return [
    'stocksData' => $stocksData,
    'mfData' => $mfData,
    'fdData' => $fdData,
    'ltfData' => $ltfData,
    'totalInvested' => (float)$totalInvested,
    'totalValue' => (float)$totalValue,
  ];
}
