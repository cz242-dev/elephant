
{ pkgs }: {
  deps = [
    pkgs.php81
    pkgs.php81Packages.composer
    pkgs.redis
    pkgs.rabbitmq-server
  ];
  
  env = {
    PHP_INI_SCAN_DIR = "/nix/store/php-config";
  };
}
