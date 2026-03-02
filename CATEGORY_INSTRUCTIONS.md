# Transaction Category Assignment Instructions

> **Version**: 2.0  
> **Last Updated**: 2026-03-02  
> **For**: GPT-5, Claude, and other AI models in expense-tracker pipeline  
> **Status**: Updated after category consolidation (duplicates merged)

---

## Overview

This is the **single source of truth** for assigning `category_id` to transactions.

After consolidation, multiple overlapping categories were merged. AI should now map all incoming transactions to the **canonical master list** below.

When uncertain, prefer a canonical business category over creating new categories.

---

## Canonical Master Category List

### Expense

| ID | Name | Use for |
|---|---|---|
| 1 | Food & Dining | Restaurants, cafes, food delivery, dining out |
| 2 | Transportation | Cabs, bus, metro, fuel, commute |
| 3 | Shopping | E-commerce, retail, fashion, electronics |
| 4 | Entertainment | Streaming, movies, games, subscriptions |
| 5 | Bills & Utilities | Electricity, water, internet, telecom, bill pay |
| 6 | Healthcare | Pharmacy, hospital, diagnostics, clinics |
| 7 | Education | Courses, fees, books, certifications |
| 8 | Travel | Flight/hotel/outstation travel bookings |
| 9 | Groceries | Supermarket, kirana, essentials |
| 10 | Insurance | Premium payments, policy renewals |
| 11 | Rent/EMI | Rent, loan EMI, amortization entries |
| 12 | Personal Care | Salon, grooming, wellness care |
| 18 | Uncategorized | Unclear/unknown transactions (fallback only) |
| 51 | Miscellaneous | Person-to-person UPI and genuinely uncategorizable spend |

### Income

| ID | Name | Use for |
|---|---|---|
| 14 | Salary | Salary credits |
| 15 | Refund | Refund/reversal credits |
| 16 | Other Income | All non-salary/non-refund income |

### Investment / Transfer

| ID | Name | Use for |
|---|---|---|
| 13 | Investments | SIP/MF/stocks/NPS/PPF investment movements |
| 17 | Transfer | Internal self-account transfers |

---

## Consolidation Rules (Important)

These old names are now merged and should **not** be reused as separate categories:

- `Bill Payment`, `Bills`, `utilities` → **Bills & Utilities (5)**
- `food`, `Food & Beverage`, `food_and_beverage`, `food_delivery`, `dining`, `Restaurant` → **Food & Dining (1)**
- `fuel`, `Transport` → **Transportation (2)**
- `Health` → **Healthcare (6)**
- `EMI`, `EMI Principal/Amortization`, `loan_payment` → **Rent/EMI (11)**
- `Other`, `Tax`, `Tax (IGST)`, `Tax component`, `interest`, `Fees`, `Online Services` → **Miscellaneous (51)**
- `UPI`, `UPI Payment`, `UPI Transfer`, `Card`, `card_spend`, `purchase`, `Purchase (tax/fee)`, `reversal`, `Unknown` → **Uncategorized (18)**
- Income-side `Income`, `Other`, `Travel`, `Shopping` → **Other Income (16)**

---

## Merchant and Pattern Mapping

### Food & Dining (ID 1)

Use for: delivery apps, restaurants, cafes, dessert outlets.

- Known: `SWIGGY`, `ZOMATO`, `STARBUCKS`, `CHAAYOS`, `MCDONALD`, `KFC`, `BURGER KING`
- Keywords: `RESTAURANT`, `CAFE`, `FOOD`, `BAKERY`, `PIZZA`, `BIRYANI`

### Groceries (ID 9)

- Known: `BIGBASKET`, `BLINKIT`, `ZEPTO`, `DMART`, `SPAR`, `RELIANCE FRESH`
- Keywords: `GROCERY`, `SUPERMARKET`, `KIRANA`, `PROVISION`

### Shopping (ID 3)

- Known: `AMAZON`, `FLIPKART`, `MYNTRA`, `AJIO`, `RELIANCE RETAIL`
- Keywords: `STORE`, `RETAIL`, `FASHION`, `BOUTIQUE`

### Travel (ID 8)

- Known: `MAKEMYTRIP`, `INDIGO`, `AIR INDIA`, `GOIBIBO`, `CLEARTRIP`, hotel merchants
- Keywords: `FLIGHT`, `AIRLINE`, `HOTEL`, `RESORT`, `TRAVEL`, `BOOKING`

### Transportation (ID 2)

- Known: `OLA`, `UBER`, `RAPIDO`, fuel stations
- Keywords: `CAB`, `TAXI`, `METRO`, `BUS`, `PETROL`, `DIESEL`

### Bills & Utilities (ID 5)

- Known: `BESCOM`, `BWSSB`, `AIRTEL`, `JIO`, `ACT`, `BBPS`
- Keywords: `ELECTRICITY`, `WATER`, `INTERNET`, `BROADBAND`, `RECHARGE`, `UTILITY`

### Healthcare (ID 6)

- Known: `APOLLO`, `NETMEDS`, `1MG`, clinics/hospitals
- Keywords: `MEDICAL`, `PHARMA`, `HOSPITAL`, `CLINIC`, `LAB`

### Insurance (ID 10)

- Known: `LIC`, `HDFC ERGO`, `STAR HEALTH`, `ICICI PRUDENTIAL`
- Keywords: `INSURANCE`, `PREMIUM`, `POLICY`

### Rent/EMI (ID 11)

- Use when description clearly indicates rent/EMI/amortization.
- Keywords: `EMI`, `AMORTIZATION`, `LOAN INSTALLMENT`, `RENT`

### Miscellaneous (ID 51)

Use for genuinely uncategorizable debit transactions and person-to-person spend.

- Typical patterns:
  - UPI payee appears to be a personal name
  - No merchant category confidence
  - Tax/fee-like lines not tied to a stronger domain category

### Uncategorized (ID 18)

Only use as final fallback if confidence is very low and transaction is not safely classifiable.

---

## Income Mapping

- Salary-like credits (`SALARY`, payroll descriptors) → **Salary (14)**
- Refund/reversal credits (`REFUND`, `REVERSAL`) → **Refund (15)**
- All remaining credits → **Other Income (16)**

---

## Transfer / Investment Mapping

- Self transfer between own accounts (`SELF TRANSFER`, clear own-beneficiary patterns) → **Transfer (17)**
- SIP/MF/stocks/NPS/PPF/deposit investment movement → **Investments (13)**

---

## Decision Order

1. If transaction type is credit: apply Income rules first.  
2. Check explicit transfer/investment markers.  
3. Match known merchants.  
4. Match keywords in merchant/description.  
5. Apply consolidation aliases (old names → canonical IDs).  
6. If still unclear: use `Miscellaneous (51)`; only use `Uncategorized (18)` as last fallback.

---

## Guardrails

- Do **not** create new categories for minor spelling variants.
- Normalize merchant text (`trim`, lowercase, collapse spaces) before matching.
- Prefer stable canonical IDs listed above.
- Keep `Uncategorized (18)` volume low; if a repeat pattern appears, map it to a proper category.

---

## Maintenance Protocol

When new recurring merchant patterns appear:

1. Add them under the relevant canonical category in this file.
2. Avoid introducing alias categories if canonical one already exists.
3. Re-run periodic duplicate check on categories table and keep taxonomy clean.

