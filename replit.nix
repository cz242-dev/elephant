
{ pkgs }: {
  deps = [
    pkgs.php81Extensions.swoole
    pkgs.php81Extensions.openswoole
    pkgs.php81
    pkgs.php81Packages.composer
    pkgs.redis
    pkgs.rabbitmq-server
  ];
}
