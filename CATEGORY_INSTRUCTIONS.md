# Transaction Category Assignment Instructions

> **Version**: 3.0  
> **Last Updated**: 2026-03-02  
> **For**: GPT-4 / Azure OpenAI SMS parser, Claude, and other AI models in expense-tracker pipeline  
> **Status**: AI now returns `category_id` integer directly — no new categories ever created by sync

---

## Overview

This is the **single source of truth** for assigning `category_id` to transactions.

**Critical change in v3.0**: The SMS parser now asks AI to return a `category_id` integer (not a name string). The PHP resolver maps any name-based fallback to a canonical ID. **No new category rows are ever created by SMS sync.**

---

## Canonical Master Category List

### Expense

| ID | Name | Icon | Color | Use for |
|---|---|---|---|---|
| 1 | Food & Dining | `restaurant-outline` | `#FF4757` | Restaurants, cafes, food delivery, dining out |
| 2 | Transportation | `car-outline` | `#FFA502` | Cabs, bus, metro, fuel, commute |
| 3 | Shopping | `bag-handle-outline` | `#2B7BE5` | E-commerce, retail, fashion, electronics |
| 4 | Entertainment | `tv-outline` | `#9C27B0` | Streaming, movies, games, subscriptions, Tata Play |
| 5 | Bills & Utilities | `flash-outline` | `#FF6B6B` | Electricity, water, internet, telecom, bill pay, recharge |
| 6 | Healthcare | `medical-outline` | `#00C48C` | Pharmacy, hospital, diagnostics, clinics |
| 7 | Education | `school-outline` | `#3F51B5` | Courses, fees, books, certifications |
| 8 | Travel | `airplane-outline` | `#FF9800` | Flight/hotel/outstation travel bookings |
| 9 | Groceries | `cart-outline` | `#4CAF50` | Supermarket, kirana, essentials |
| 10 | Insurance | `shield-checkmark-outline` | `#607D8B` | Premium payments, policy renewals |
| 11 | Rent/EMI | `home-outline` | `#795548` | Rent, loan EMI, amortization entries |
| 12 | Personal Care | `person-outline` | `#E91E63` | Salon, grooming, wellness care |
| 18 | Uncategorized | `help-circle-outline` | `#BDBDBD` | Final fallback only — use sparingly |
| 51 | Miscellaneous | `ellipsis-horizontal-circle-outline` | `#FF5722` | P2P UPI, ATM withdrawal, fees, tax, genuinely unclear debits |

### Income

| ID | Name | Icon | Color | Use for |
|---|---|---|---|---|
| 14 | Salary | `cash-outline` | `#4CAF50` | Salary credits |
| 15 | Refund | `return-down-back-outline` | `#8BC34A` | Refund/reversal/cashback credits |
| 16 | Other Income | `wallet-outline` | `#CDDC39` | All non-salary/non-refund income |

### Investment / Transfer

| ID | Name | Icon | Color | Use for |
|---|---|---|---|---|
| 13 | Investments | `trending-up-outline` | `#00BCD4` | SIP/MF/stocks/NPS/PPF investment movements |
| 17 | Transfer | `swap-horizontal-outline` | `#9E9E9E` | Internal self-account transfers |

---

## AI Prompt Behaviour (SMS Parser — v3.0)

The Azure OpenAI SMS parser prompt now instructs AI to:
1. Return `category_id` as an **integer** from the canonical list above
2. Never invent category names or IDs outside this list
3. Use `51` (Miscellaneous) for ATM withdrawals, P2P UPI, ambiguous debits
4. Use income IDs (14/15/16) for credit transactions

The PHP resolver (`resolveCategoryId`) additionally:
- Validates the returned `category_id` against the canonical list
- Falls back to name-alias lookup if ID is invalid
- Falls back to `51` or `16` (for credits) if still unresolved
- **Never calls `INSERT INTO categories`** — rogue category creation is eliminated

---

## Consolidation Rules (Important)

These old names are now merged and should **not** be reused as separate categories:

- `Bill Payment`, `Bills`, `utilities` → **Bills & Utilities (5)**
- `food`, `Food & Beverage`, `food_and_beverage`, `food_delivery`, `dining`, `Restaurant` → **Food & Dining (1)**
- `fuel`, `Transport` → **Transportation (2)**
- `Health` → **Healthcare (6)**
- `Streaming`, `Tata Play`, `OTT` → **Entertainment (4)**
- `EMI`, `EMI Principal/Amortization`, `loan_payment` → **Rent/EMI (11)**
- `Other`, `Tax`, `Tax (IGST)`, `Tax component`, `interest`, `Fees`, `Online Services`, `ATM`, `ATM Withdrawal`, `UPI`, `UPI Payment`, `UPI Transfer`, `Card`, `card_spend`, `purchase`, `Purchase (tax/fee)`, `reversal` → **Miscellaneous (51)**
- `Unknown` → **Uncategorized (18)**
- Income-side `Income`, `Other` → **Other Income (16)**

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

