# uncomment one of below base images
#FROM tezet/base-x86
FROM tezet/base-arm

RUN \
	apt-dpkg-wrap apt-get update && \
	apt-dpkg-wrap apt-get install -y php-cgi php-cli php-common php-json php-mysql php-readline php-sqlite3 sqlite3 \
        lighttpd ser2net libfcgi-perl  mosquitto-clients python3 python3-pip netbase && \
    pip3 install setuptools wheel && \
	pip3 install flask flask_mqtt flask_socketio eventlet && \
    apt-cleanup

COPY rootfs/ /

EXPOSE 8080

VOLUME /config
