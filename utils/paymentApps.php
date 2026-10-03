<?php

/**
 * PaymentApps
 *
 * The apps whose notifications POST /parse/notification accepts, keyed by Android
 * package name. Mirrors android/.../core/capture/PaymentNotificationRules.kt — keep
 * the two lists in step.
 *
 * kind: upi_app | wallet | card_app | bank_app. Bank apps carry the bank enum so the
 * row lands on the right bank_accounts entry without the AI having to name it.
 */
class PaymentApps
{
    private const APPS = [
        'com.phonepe.app' => ['label' => 'PhonePe', 'kind' => 'upi_app', 'key' => 'PHONEPE'],
        'com.google.android.apps.nbu.paisa.user' => ['label' => 'Google Pay', 'kind' => 'upi_app', 'key' => 'GPAY'],
        'net.one97.paytm' => ['label' => 'Paytm', 'kind' => 'upi_app', 'key' => 'PAYTM'],
        'in.org.npci.upiapp' => ['label' => 'BHIM', 'kind' => 'upi_app', 'key' => 'BHIM'],
        'in.amazon.mShop.android.shopping' => ['label' => 'Amazon Pay', 'kind' => 'upi_app', 'key' => 'AMAZONPAY'],
        'com.dreamplug.androidapp' => ['label' => 'CRED', 'kind' => 'card_app', 'key' => 'CRED'],
        'com.snapwork.hdfc' => ['label' => 'HDFC Bank', 'kind' => 'bank_app', 'key' => 'HDFC', 'bank' => 'hdfc'],
        'com.csam.icici.bank.imobile' => ['label' => 'ICICI iMobile', 'kind' => 'bank_app', 'key' => 'ICICI', 'bank' => 'icici'],
        'com.sbi.lotusintouch' => ['label' => 'SBI YONO', 'kind' => 'bank_app', 'key' => 'SBI', 'bank' => 'sbi'],
        'com.axis.mobile' => ['label' => 'Axis Mobile', 'kind' => 'bank_app', 'key' => 'AXIS', 'bank' => 'axis'],
        'com.msf.kbank.mobile' => ['label' => 'Kotak', 'kind' => 'bank_app', 'key' => 'KOTAK', 'bank' => 'kotak'],
        'com.idfcfirstbank.optimus' => ['label' => 'IDFC FIRST', 'kind' => 'bank_app', 'key' => 'IDFC', 'bank' => 'idfc'],
    ];

    /** Debug builds report `adb shell cmd notification post` as this package. */
    public const TEST_PACKAGE = 'test.shell';

    public static function find(string $package, bool $allowTest = false): ?array
    {
        if (isset(self::APPS[$package])) {
            return self::APPS[$package] + ['package' => $package];
        }
        if ($allowTest && $package === self::TEST_PACKAGE) {
            return ['label' => 'Test', 'kind' => 'upi_app', 'key' => 'TEST', 'package' => $package];
        }
        return null;
    }

    public static function isCred(?string $package): bool
    {
        return $package === 'com.dreamplug.androidapp';
    }
}
