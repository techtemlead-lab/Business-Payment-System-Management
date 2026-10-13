<?php
namespace App\Support;
final class YouTubeVideo {
    public static function id(string $url):?string {
        $parts=parse_url(trim($url));if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https'],true))return null;
        $host=strtolower($parts['host']??'');$path=trim($parts['path']??'','/');$id=null;
        if($host==='youtu.be')$id=explode('/',$path)[0];
        elseif(in_array($host,['youtube.com','www.youtube.com','m.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com'],true)){
            if($path==='watch'){parse_str($parts['query']??'',$q);$id=$q['v']??null;}
            elseif(preg_match('#^(?:embed|shorts|live)/([^/]+)$#',$path,$m))$id=$m[1];
        }
        return is_string($id)&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)?$id:null;
    }
}
