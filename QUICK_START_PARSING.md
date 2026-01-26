# Quick Start: SMS/Email Parsing

## Prerequisites

1. **Install PHP Dependencies**
```bash
cd expense-tracker/server
composer install
```

2. **Configure Environment Variables**

Add to `server/.env`:
```env
# Azure OpenAI (required for SMS parsing)
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=your-api-key-here
AZURE_OPENAI_DEPLOYMENT=gpt-4-turbo

# Database
DB_HOST=localhost
DB_NAME=expense_tracker
DB_USER=root
DB_PASS=

# JWT
JWT_SECRET=your-secret-key-here
```

---

## Usage Examples

### 1. Parse SMS Messages (Batch)

```bash
curl -X POST http://localhost:8000/api/parse/sms \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "messages": [
      {
        "sender": "VK-HDFCBK",
        "body": "Rs. 500.00 debited from A/c XX1234 on 20-Jan-26 at SWIGGY. Avl Bal: Rs. 5000.00",
        "date": "2026-01-20 14:30:00"
      }
    ]
  }'
```

### 2. Setup Gmail OAuth2

```bash
curl -X POST http://localhost:8000/api/parse/email/setup \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "credentials": {
      "web": {
        "client_id": "your-client-id.apps.googleusercontent.com",
        "client_secret": "your-client-secret",
        "redirect_uris": ["http://localhost:8000/api/parse/email/callback"]
      }
    }
  }'
```

Then visit the returned `auth_url` and authorize.

### 3. Fetch and Parse CAMS/KFintech Emails

```bash
curl -X POST http://localhost:8000/api/parse/email/fetch \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "query": "from:camsonline.com subject:statement",
    "max_results": 5
  }'
```

---

## Integration with Mobile App

### Forward SMS on Receive (React Native)

Add to your Android SMS receiver:

```typescript
import { api } from './services/api';

const handleSMSReceived = async (sender: string, body: string) => {
  // Only forward bank SMS
  const banks = ['hdfc', 'sbi', 'icici', 'idfc', 'rbl'];
  if (!banks.some(bank => sender.toLowerCase().includes(bank))) {
    return;
  }

  try {
    await api.post('/parse/sms/webhook', {
      sender,
      body,
      date: new Date().toISOString()
    });
    console.log('SMS forwarded to server for parsing');
  } catch (error) {
    console.error('Failed to forward SMS:', error);
  }
};
```

---

## Comparison: Node.js Scraper vs PHP Server

| Feature | Node.js Scraper | PHP Server |
|---------|----------------|------------|
| **SMS Parsing** | File/ADB based | Webhook ready |
| **Email Parsing** | Gmail OAuth2 | Gmail OAuth2 |
| **Real-time** | No (manual trigger) | Yes (webhooks) |
| **State Tracking** | File-based sync state | Database |
| **Use Case** | Batch processing | Real-time forwarding |
| **Setup** | `npm run dev` | Always running |

**Recommendation:** Use both!
- **Node.js scraper** for historical data import (bulk SMS/emails)
- **PHP server** for real-time transaction capture via mobile app

---

## Troubleshooting

### "Azure OpenAI credentials not configured"
- Check server/.env has all three variables
- Restart PHP server after adding credentials

### "Gmail not authenticated"
- Complete OAuth2 flow: /setup → visit auth_url → /callback with code
- Check gmail_token_{userId}.json exists in server/data/

### "Unauthorized: Invalid token"
- Ensure JWT token is included in Authorization header
- Token format: `Bearer eyJ0eXAiOiJKV1QiLCJhbGc...`

---

## Next Steps

1. ✅ Install composer dependencies
2. ✅ Configure Azure OpenAI credentials
3. ✅ Test SMS parsing with sample data
4. ✅ Setup Gmail OAuth2 (optional)
5. ✅ Integrate mobile app SMS forwarding
6. ✅ Test end-to-end real-time flow

For detailed API documentation, see [SMS_EMAIL_PARSER_API.md](SMS_EMAIL_PARSER_API.md)
