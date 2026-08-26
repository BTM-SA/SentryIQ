<?php

declare(strict_types=1);

function generate_2fa_html(string $two_fa_code, string $copy_url): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head><body style="margin:0;padding:30px;background:#f4f6f9;font-family:Arial,sans-serif;"><div style="max-width:500px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;border:1px solid #e1e4e8;"><h2 style="margin-top:0;color:#1a1a1a;">🔒 SentryIQ Vault Access</h2><p style="color:#333;font-size:15px;">Your temporary verification code is:</p><div style="font-size:34px;font-weight:bold;letter-spacing:7px;margin:25px 0;color:#0066cc;">' . htmlspecialchars($two_fa_code, ENT_QUOTES, 'UTF-8') . '</div><p style="color:#555;font-size:14px;">This code expires in <strong>5 minutes</strong>.</p><p style="margin-top:25px;"><a href="' . htmlspecialchars($copy_url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0066cc;color:#fff;text-decoration:none;padding:12px 24px;border-radius:5px;font-weight:bold;font-size:14px;">Copy Code</a></p><p style="margin-top:25px;font-size:12px;color:#777;line-height:1.5;">If you did not request access to your password vault, safely ignore this email.</p></div></body></html>';
}
