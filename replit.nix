
{ pkgs }: {
  deps = [
    pkgs.php81
    pkgs.php81Packages.composer
    pkgs.php81Extensions.swoole
    pkgs.redis
    pkgs.rabbitmq-server
  ];
}
