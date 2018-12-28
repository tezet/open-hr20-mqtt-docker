# OpenHR20 docker with MQTT support

OpenHR20 docker for x86 and arm (rpi). This is very early and dirty implementation, but provides enough functionality to use it with mqtt climate module in Home Assistant.

Need to fix:
  - Move all hardcoded configuration into docker ENV variables
  - Get rid of legacy OpenHR20 stuff and rely solely on MQTT

### Installation

Need to fix:
  - Expose serial port on TCP port on host machine, e.g. ser2net -C 192.168.1.2,4444:raw:0:/dev/ttyUSB0:38400
  - Clone the repository
  - Copy example.env into .env and edit
  - Edit config.php, daemon.php and hr20_mqtt.py for your needs (you can find them in rootfs dir)
  - Select architecture of tha base image (x86 or arm)


```sh
$ make
$ docker-compose up
