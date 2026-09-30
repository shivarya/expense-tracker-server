<?php

/**
 * An EMI's next_payment_date is only rewritten when a statement/scrape sync touches the row, so between syncs an
 * auto-debit EMI keeps showing a date that has already passed as "upcoming". For an active auto-debit EMI, roll the
 * stored date forward one month at a time (on its due day) until it is today or later. A non-auto-debit EMI keeps its
 * stored date: a missed manual payment should stay visibly overdue rather than be papered over.
 */
function emiRolledNextPaymentDate(array $emi, ?DateTimeImmutable $today = null): ?string
{
  $stored = $emi['next_payment_date'] ?? null;
  if (empty($stored)) {
    return null;
  }
  $today = $today ?? new DateTimeImmutable('today');
  $next = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $stored, 0, 10));
  if ($next === false || $next >= $today) {
    return $stored;
  }
  if (($emi['status'] ?? 'active') !== 'active' || empty($emi['auto_debit'])) {
    return $stored;
  }

  $dueDay = (int) ($emi['due_date'] ?? 0);
  if ($dueDay < 1 || $dueDay > 31) {
    $dueDay = (int) $next->format('j');
  }
  while ($next < $today) {
    $first = $next->modify('first day of next month');
    $day = min($dueDay, (int) $first->format('t'));
    $next = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
  }
  return $next->format('Y-m-d');
}
