
{ pkgs }: {
  deps = [
    pkgs.php81
    pkgs.php81Extensions.mbstring
    pkgs.php81Extensions.iconv
    pkgs.php81Extensions.filter
    pkgs.php81Extensions.openssl
    pkgs.php81Extensions.swoole
    pkgs.php81Packages.composer
    pkgs.redis
    pkgs.rabbitmq-server
  ];
  
  env = {
    PHP_INI_SCAN_DIR = "${pkgs.php81}/lib";
  };
}
