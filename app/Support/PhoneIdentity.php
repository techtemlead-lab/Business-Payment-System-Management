<?php
namespace App\Support;

final class PhoneIdentity
{
    // This same expression is indexed for existing, staff-maintained phone values.
    public const SQL = "CASE WHEN regexp_replace(phone, '[^0-9]', '', 'g') LIKE '00%' THEN substr(regexp_replace(phone, '[^0-9]', '', 'g'), 3) WHEN char_length(regexp_replace(phone, '[^0-9]', '', 'g')) = 11 AND regexp_replace(phone, '[^0-9]', '', 'g') LIKE '01%' THEN '88' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(phone, '[^0-9]', '', 'g') END";

    public static function normalize(string $input):?string
    {
        $digits=preg_replace('/[\s()+.\-]/u','',trim($input));
        if(!is_string($digits)||!preg_match('/^[0-9]{10,17}$/D',$digits))return null;
        if(str_starts_with($digits,'00'))$digits=substr($digits,2);
        elseif(strlen($digits)===11&&str_starts_with($digits,'01'))$digits='88'.$digits;
        return strlen($digits)>=10&&strlen($digits)<=15?$digits:null;
    }
}
