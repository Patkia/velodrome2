# Velodrome Position Monitor

PHP monitor สำหรับตรวจ Liquidity Position ที่ stake อยู่บน Velodrome หลายเชน ได้แก่ Optimism, Celo และ Soneium

## ความสามารถ

- ตรวจ Position ใน gauge ที่กำหนด
- แสดงสถานะ In Range / Out of Range
- แสดง Current Value, Initial Value, P/L, สัดส่วนเหรียญ และ reward
- แจ้ง Telegram เมื่อ Position เปลี่ยนเป็น Out of Range
- เก็บ local state เพื่อป้องกันการแจ้งเตือนซ้ำ และล้าง state เมื่อถอน Position แล้ว

ระบบปัจจุบันเป็น **monitor และ alert เท่านั้น** ไม่ได้ส่งธุรกรรม withdraw, rebalance หรือ add liquidity

## Requirements

- PHP 8.3
- Composer
- PHP cURL extension

## Local setup

1. ติดตั้ง dependencies: `composer install`
2. คัดลอก `config/config.example.php` เป็น `config/config.php`
3. คัดลอก `config/config_tg1.example.php` เป็น `config/config_tg1.php`
4. กำหนด Environment Variables หรือใส่ค่าท้องถิ่นในไฟล์ config ที่ถูก ignore

Environment Variables ที่รองรับ:

- `WALLET_ADDRESS`
- `OPTIMISM_RPC_URL`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`
- `NOTIFICATIONS_ENABLED`

รัน monitor:

```bash
php index.php
```

ทดสอบโดยไม่ส่ง Telegram:

```bash
NOTIFICATIONS_ENABLED=false php index.php
```

## Security

ห้าม commit ไฟล์จริงใน `config/`, `.env`, `state/` หรือ `vendor/` ขึ้น GitHub. ใช้ไฟล์ example สำหรับแชร์โครงสร้าง config เท่านั้น.
