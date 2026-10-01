<?php

/**
 * EMI rows are only rewritten when a statement/scrape sync touches them, so between syncs `next_payment_date`,
 * `remaining_months` and `status` all go stale: an EMI whose last installment has already been billed keeps showing
 * as "upcoming", and the installment counters never move. These helpers derive the live picture at read time.
 *
 * - Credit-card EMIs (the card's account_type, or loan_type 'credit_card') run on a fixed schedule: installment k
 *   (0-based) falls on start_date + k months, for tenure_months installments. Installments dated before today are
 *   paid, so the next date, the counters and "completed" all come from that schedule. The stored counter still wins
 *   if a sync knows better (e.g. a foreclosure).
 * - Other loans (home loan etc.) take their counters from the bank via sync, so the stored values stay the baseline;
 *   an active auto-debit loan is rolled forward on its due day and each rolled month counts as one more paid
 *   installment. A non-auto-debit loan keeps its stored date: a missed manual payment should stay visibly overdue.
 */

/** Date of installment $k (0-based) of a schedule starting on $start, clamped to the month's length. */
function emiInstallmentDate(DateTimeImmutable $start, int $k): DateTimeImmutable
{
  $day = (int) $start->format('j');
  $first = $start->modify('first day of this month')->modify("+{$k} months");
  return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')));
}

function emiIsCardEmi(array $emi): bool
{
  return ($emi['account_type'] ?? null) === 'credit_card' || ($emi['loan_type'] ?? null) === 'credit_card';
}

/**
 * @return array{next_payment_date: ?string, paid_installments: int, total_installments: int,
 *               remaining_installments: int, status: string}
 */
function emiProgress(array $emi, ?DateTimeImmutable $today = null): array
{
  $today = $today ?? new DateTimeImmutable('today');
  $stored = !empty($emi['next_payment_date']) ? (string) $emi['next_payment_date'] : null;
  $status = (string) ($emi['status'] ?? 'active');
  $total = max(0, (int) ($emi['tenure_months'] ?? 0));
  $storedPaid = $total > 0 ? min($total, max(0, $total - (int) ($emi['remaining_months'] ?? 0))) : 0;

  $result = static fn(?string $next, int $paid, string $st) => [
    'next_payment_date' => $next,
    'paid_installments' => $paid,
    'total_installments' => $total,
    'remaining_installments' => max(0, $total - $paid),
    'status' => $st,
  ];

  if ($status !== 'active') {
    return $result($stored, $status === 'paid' ? $total : $storedPaid, $status);
  }

  if (emiIsCardEmi($emi) && $total > 0 && !empty($emi['start_date'])) {
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $emi['start_date'], 0, 10));
    if ($start !== false) {
      $paid = 0;
      while ($paid < $total && emiInstallmentDate($start, $paid) < $today) {
        $paid++;
      }
      $paid = max($paid, $storedPaid);
      if ($paid >= $total) {
        return $result(null, $total, 'paid');
      }
      return $result(emiInstallmentDate($start, $paid)->format('Y-m-d'), $paid, 'active');
    }
  }

  $paid = $storedPaid;
  $next = $stored !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($stored, 0, 10)) : false;
  if ($next === false || $next >= $today || empty($emi['auto_debit'])) {
    return $result($stored, $paid, 'active');
  }

  $dueDay = (int) ($emi['due_date'] ?? 0);
  if ($dueDay < 1 || $dueDay > 31) {
    $dueDay = (int) $next->format('j');
  }
  while ($next < $today) {
    $first = $next->modify('first day of next month');
    $day = min($dueDay, (int) $first->format('t'));
    $next = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    $paid++;
  }
  if ($total > 0 && $paid >= $total) {
    return $result(null, $total, 'paid');
  }
  return $result($next->format('Y-m-d'), $total > 0 ? min($paid, $total) : $paid, 'active');
}

/** Overlays the live progress onto an EMI row (the shape both /dashboard and /emis return). */
function emiApplyProgress(array &$emi, ?DateTimeImmutable $today = null): void
{
  $p = emiProgress($emi, $today);
  $emi['next_payment_date'] = $p['next_payment_date'];
  $emi['paid_installments'] = $p['paid_installments'];
  $emi['total_installments'] = $p['total_installments'];
  $emi['remaining_installments'] = $p['remaining_installments'];
  if ($p['total_installments'] > 0) {
    $emi['remaining_months'] = $p['remaining_installments'];
  }
  if ($p['status'] !== ($emi['status'] ?? 'active')) {
    $emi['status'] = $p['status'];
    if ($p['status'] === 'paid') {
      $emi['remaining_principal'] = '0.00';
    }
  }
}

/** Kept for callers that only need the date. */
function emiRolledNextPaymentDate(array $emi, ?DateTimeImmutable $today = null): ?string
{
  return emiProgress($emi, $today)['next_payment_date'];
}
