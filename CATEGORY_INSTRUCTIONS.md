# Transaction Category Assignment Instructions

> **Version**: 1.0  
> **Last Updated**: 2026-02-28  
> **For**: GPT-5, Claude, and other LLMs used by the expense-tracker AI pipeline  
> **Data Source**: Production DB analysis of 724+ categorized transactions

---

## Overview

This file provides rules and patterns for assigning a `category_id` to incoming financial transactions (SMS, email, credit-card scrapes). Both the PHP server's auto-categorizer and external AI agents should use this file as the single source of truth.

When the AI model receives a transaction with `merchant`, `description`, `amount`, and/or `source_data`, it should return the best-matching category from the list below.

---

## Available Categories

| ID  | Name            | Type    | Icon                 | Color    | Description                                   |
|-----|-----------------|---------|----------------------|----------|-----------------------------------------------|
| –   | Food & Dining   | expense | restaurant-outline   | #FF5722  | Restaurants, cafés, food delivery, dining out  |
| –   | Groceries       | expense | basket-outline       | #8BC34A  | Supermarkets, grocery stores, daily essentials |
| –   | Shopping        | expense | cart-outline         | #E91E63  | E-commerce, retail stores, clothing, gadgets   |
| –   | Travel          | expense | airplane-outline     | #3F51B5  | Flights, hotels, cabs, travel bookings         |
| –   | Entertainment   | expense | film-outline         | #9C27B0  | Subscriptions, movies, gaming, streaming       |
| –   | Bills           | expense | receipt-outline      | #795548  | Insurance, utility bills, recharges            |
| –   | Healthcare      | expense | medkit-outline       | #4CAF50  | Pharmacy, hospitals, medical purchases         |
| –   | Fuel            | expense | speedometer-outline  | #FF9800  | Petrol, diesel, EV charging                    |
| –   | Rent            | expense | home-outline         | #607D8B  | House rent, maintenance charges                |
| –   | EMI             | expense | card-outline         | #F44336  | Loan EMI payments, credit card EMIs            |
| –   | Transfers       | transfer| swap-horizontal      | #2196F3  | Self-transfers, fund movements                 |
| –   | Investments     | income  | trending-up-outline  | #009688  | SIP, mutual funds, stock purchases             |
| –   | Miscellaneous   | expense | pricetag-outline     | #757575  | Fallback for UPI payments to individuals       |
| –   | Other           | expense | card-outline         | #9C27B0  | Catch-all for unrecognized card transactions   |

> **Note**: Category IDs are user-specific. Look up the user's categories by name before assigning. Use the `/categories` API to get actual IDs.

---

## Merchant → Category Rules

### Food & Dining (90 transactions)
Pattern: Restaurants, cafés, delivery apps, food stalls

**Known merchants** (assign immediately):
- `SWIGGY`, `ZOMATO`, `DUNZO` → Food & Dining (delivery)
- `STARBUCKS`, `TATA STARBUCKS`, `CHAAYOS` → Food & Dining (café)
- `MCDONALD`, `KFC`, `BURGER KING`, `TACO BELL` → Food & Dining (fast food)
- `SANGAM RESTAURANT`, `RAJDHANI`, `NANDHINI`, `DISTRICT DINING` → Food & Dining (restaurant)
- `IBACO`, `SMOOTH GELATO`, `AMADORA GOURMET` → Food & Dining (dessert)
- `PURANI DILLI`, `SMOOR` → Food & Dining (restaurant/café)

**Keywords in merchant name**:
- Contains: `RESTAURANT`, `CAFE`, `KITCHEN`, `FOOD`, `BAKERY`, `BIRYANI`, `PIZZA`, `CHAI`, `DOSA`, `SWEET` → Food & Dining
- Contains: `SWEETS`, `JUICE`, `GELATO`, `ICE CREAM` → Food & Dining

**Description prefix**: `"Dining Out at"`, `"Food Delivery at"` → Food & Dining

**Source data**: `merchant_category: "restaurant"` or `"food_delivery"` → Food & Dining

---

### Groceries (28 transactions)
Pattern: Supermarkets, daily essentials, department stores

**Known merchants**:
- `BIGBASKET`, `BLINKIT`, `ZEPTO`, `INSTAMART` → Groceries
- `DMART`, `MORE`, `RELIANCE FRESH`, `STAR BAZAAR` → Groceries
- `ROLLA HYPER MARKET` → Groceries
- `NATURE'S BASKET`, `SPAR` → Groceries

**Keywords**: `GROCERY`, `SUPERMARKET`, `MARKET`, `BAZAAR`, `PROVISION`, `KIRANA`, `DEPARTMENTAL` → Groceries

**Description prefix**: `"Grocery Shopping at"` → Groceries

**Source data**: `merchant_category: "grocery"` → Groceries

---

### Shopping (55 transactions)
Pattern: E-commerce, retail brands, clothing, electronics

**Known merchants**:
- `AMAZON`, `AMAZON PAY`, `AMAZON PGSI` → Shopping
- `FLIPKART`, `MYNTRA`, `AJIO` → Shopping
- `WESTSIDE`, `LIFESTYLE INTERNATIONAL` → Shopping (clothing)
- `METRO BRANDS`, `M S KAPOOR FOOTWEAR` → Shopping (footwear)
- `ADITYA BIRLA FASHION` → Shopping (clothing)
- `THE SOULED STORE`, `M S MODENIK LIFESTYLE` → Shopping (apparel)
- `THE LUGGAGE BOUTIQUE` → Shopping (accessories)
- `RELIANCE RETAIL` → Shopping (retail)

**Keywords**: `AMAZON`, `FLIPKART`, `MYNTRA`, `FASHION`, `LIFESTYLE`, `BOUTIQUE`, `STORE`, `RETAIL` → Shopping

**EMI patterns**: Any `"Principal Amount Amortization"` or `"Interest Amount Amortization"` where the tail contains `AMAZON` → Shopping

**Description prefix**: `"Online Shopping at"` → Shopping

**Source data**: `merchant_category: "retail_shopping"` → Shopping

---

### Travel (56 transactions)
Pattern: Airlines, hotels, travel aggregators, taxis

**Known merchants**:
- `MAKEMYTRIP`, `MAKE MY TRIP`, `MMT` → Travel
- `INDIGO`, `INDIGO AIRLINE`, `SPICEJET`, `AIR INDIA`, `VISTARA` → Travel (flights)
- `OLA`, `UBER`, `RAPIDO`, `MERU` → Travel (cabs) — **Note**: short urban rides under ₹500 may also be Transport if that category exists
- `DAIWIK HOTELS`, `HOTEL RAMESWARAM`, `080 TRANSIT HOTEL` → Travel (hotels)
- `BOOKMYSHOW` → Travel (if associated with event at a travel destination) or Entertainment
- `CLEARTRIP`, `GOIBIBO`, `YATRA` → Travel
- `YOM` (cab aggregator) → Travel
- `ZELEVEN HOSPITALITY` → Travel
- `GMR HOSPITALITY` → Travel (airport)
- `FASTAG` → Travel (toll)

**EMI patterns**: `"Amortization"` containing `MAKEMYTRIP` → Travel

**Keywords**: `AIRLINE`, `HOTEL`, `RESORT`, `TRAVEL`, `FLIGHT`, `BOOKING`, `TRIP`, `HOSPITALITY`, `AIRPORT` → Travel

**Description prefix**: `"Travel Booking at"`, `"Hotel Stay at"` → Travel

**Source data**: `merchant_category: "travel_booking"` or `"hotel"` → Travel

---

### Entertainment (4 transactions)
Pattern: Subscriptions, streaming, movies, gaming

**Known merchants**:
- `NETFLIX`, `HOTSTAR`, `PRIME VIDEO` → Entertainment
- `SPOTIFY`, `YOUTUBE PREMIUM`, `APPLE MUSIC` → Entertainment
- `GOOGLE PLAY`, `GOOGLEPLAY` → Entertainment (subscriptions)
- `CINEPOLIS`, `PVR`, `INOX` → Entertainment (movies)
- `BOOK MY SHOW` (if movie/concert tickets) → Entertainment

**Keywords**: `SUBSCRIPTION`, `STREAMING`, `CINEMA`, `THEATER`, `GAMING`, `PLAY STORE` → Entertainment

**Description prefix**: `"Subscription Service at"` → Entertainment

**Source data**: `merchant_category: "subscription"` → Entertainment

---

### Bills & Utilities (2+ transactions)
Pattern: Insurance, phone recharges, electricity, internet

**Known merchants**:
- `BESCOM`, `BWSSB` → Bills (electricity/water)
- `AIRTEL`, `JIO`, `VI`, `BSNL` → Bills (telecom)
- `ACT FIBERNET`, `HATHWAY` → Bills (internet)
- `LIC`, `STAR HEALTH`, `HDFC ERGO`, `ICICI PRUDENTIAL` → Bills (insurance)
- `BBPS PAYMENT RECEIVED` → Bills (utility payment)

**Keywords**: `INSURANCE`, `PREMIUM`, `RECHARGE`, `ELECTRICITY`, `WATER`, `GAS`, `BROADBAND`, `INTERNET`, `BBPS` → Bills

**Description prefix**: `"Insurance Premium at"` → Bills

**Source data**: `merchant_category: "insurance"` → Bills

---

### Healthcare (2+ transactions)
Pattern: Pharmacies, hospitals, medical

**Known merchants**:
- `APOLLO`, `APOLLO247`, `CREDPAYAPOLLO247` → Healthcare
- `MOKSH MEDICALS` → Healthcare
- `NETMEDS`, `PHARMEASY`, `1MG` → Healthcare
- `SATHYA SAI ORTHOPAEDI` → Healthcare

**Keywords**: `MEDICAL`, `PHARMA`, `HOSPITAL`, `CLINIC`, `HEALTH`, `ORTHO`, `DENTAL`, `DIAGNOSTIC`, `LAB`, `MEDIC` → Healthcare

**Description prefix**: `"Medical Purchase at"` → Healthcare

**Source data**: `merchant_category: "pharmacy"` → Healthcare

---

### Fuel
Pattern: Petrol pumps, EV charging

**Known merchants**:
- `HP PETROL`, `INDIAN OIL`, `BHARAT PETROLEUM`, `SHELL` → Fuel
- `ATHER`, `TATA POWER EZ CHARGE` → Fuel (EV)

**Keywords**: `PETROL`, `DIESEL`, `FUEL`, `PETROLEUM`, `GAS STATION`, `EV CHARGE` → Fuel

---

### EMI
Pattern: Loan installments clearly labeled as EMI

**Patterns**:
- Description contains `"EMI"` and not covered by Shopping/Travel EMI rules → EMI
- `"Principal Amount Amortization"` or `"Interest Amount Amortization"` for non-retail/travel merchants → EMI

---

### Transfers
Pattern: Self-transfers between own accounts

**Patterns**:
- Same person's name appears as both sender and receiver
- Description contains `"SELF TRANSFER"`, `"NEFT"`, `"RTGS"`, `"IMPS"` where the beneficiary matches user's name
- `PTM` (Paytm) credits that match recent debits → likely Transfers

---

### Investments
Pattern: SIP, mutual funds, stock purchases

**Known merchants**:
- `ZERODHA`, `GROWW`, `KUVERA`, `COIN` → Investments
- `SBI MF`, `HDFC MF`, `NIPPON MF` → Investments
- `NPS`, `PPF` → Investments

**Keywords**: `MUTUAL FUND`, `SIP`, `STOCK`, `SHARE`, `INVESTMENT`, `DEMAT`, `NPS`, `PPF`, `FD` → Investments

---

### Miscellaneous (377 transactions — largest bucket)
Pattern: UPI payments to individuals (person names), unrecognized small merchants

**When to use**:
- Merchant is a **person's name** (e.g., `BABULLA SINGH`, `RAJENDRA SINGH`, `VINAY M`, `MRS CHOWDAMMA`)
- UPI payment with no clear business name
- `source_data.merchant_category: "upi_payment"` AND merchant appears to be a person → Miscellaneous
- Amount is typically small (₹20–₹500 range for individual-to-individual)

**When NOT to use**: If the "person" name is actually a business (e.g., `Baby Care` → could be Groceries/Shopping), try to identify the business first.

---

### Other (108 transactions)
Pattern: Card transactions that don't fit elsewhere, refunds, tax entries

**When to use**:
- `IGST`, `CGST`, `SGST` tax line items → Other
- Refunds: `"Online Refund"` → Other
- `GODADDY`, `SSLSCOM` (domain/hosting) → Other (or Bills if user prefers)
- `ADANIDIGITALLABS` (digital services) → Other
- Any credit-card transaction that doesn't match above categories

---

## Decision Algorithm

```
1. Check source_data.merchant_category → direct category mapping
2. Check merchant name against Known Merchants list (exact/substring match)
3. Check merchant name for Keywords
4. Check description prefix patterns
5. Check EMI/Amortization patterns → Shopping or Travel EMI parent
6. If merchant is a person's name + UPI payment → Miscellaneous
7. If credit card transaction with no match → Other
8. If nothing matches → Miscellaneous (with low confidence flag)
```

## Confidence Scoring

When assigning a category, also return a confidence score:

| Confidence | When                                                        |
|------------|-------------------------------------------------------------|
| High       | Exact merchant match from known list, or source_data match  |
| Medium     | Keyword match in merchant/description                       |
| Low        | Fallback to Miscellaneous/Other, or ambiguous merchants     |

Include this in `source_data`:
```json
{
  "categoryConfidence": "High",
  "categoryConfidenceScore": 0.95,
  "categoryReason": "Exact merchant match: SWIGGY → Food & Dining"
}
```

---

## Handling Ambiguous Cases

1. **BOOKMYSHOW** → Entertainment (movies/events) unless context says travel event
2. **CLEARTRIP** → Travel (even though under Miscellaneous in current data — should be moved)
3. **Person names doing business** (e.g., `Baby Care`, `S S Enterprises`) → If the amount is large or recurring, consider Shopping/Services
4. **Amazon EMIs** → Shopping (regardless of EMI label)
5. **MakeMyTrip EMIs** → Travel
6. **GOOGLE PLAY / APPLE** → Entertainment (subscriptions) unless clearly app purchase

---

## Re-categorization Heuristics

When a user manually updates a transaction's category, the system should learn:

1. **Record the merchant→category mapping** in a lookup table
2. **If the same merchant appears 3+ times** with user-corrected category, auto-apply that category going forward
3. **Update this instruction file** periodically with new merchant patterns from user corrections

---

## File Update Protocol

This file should be updated when:
- New categories are added
- Users correct 10+ transactions to a new pattern
- A new data source (bank, card) is added
- Monthly review reveals systematic miscategorization

To update, run the analysis query on the production DB:
```sql
SELECT c.name as category, t.merchant, COUNT(*) as cnt
FROM transactions t 
JOIN categories c ON t.category_id = c.id 
WHERE t.merchant IS NOT NULL 
GROUP BY c.name, t.merchant 
HAVING cnt >= 2
ORDER BY c.name, cnt DESC;
```

---

## Notes for AI Agents

- Indian merchants often have truncated names in SMS (e.g., `MAKEMYTRIP COM`, `INDIGO A IRLINE`) — use fuzzy matching
- UPI merchant names may have spaces inserted mid-word (SMS character limits) — normalize by removing extra spaces
- Amount can help disambiguate:  small ₹20-200 UPI → likely Miscellaneous (individual), ₹500+ → could be a business
- Credit card transactions may prefix with EMI info (e.g., `Principal Amount Amortization - <3/6>AMAZON PAY`)
- Always prefer the user's historical correction over rule-based assignment
