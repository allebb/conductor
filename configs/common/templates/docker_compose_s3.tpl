services:
  versitygw:
    image: "@@S3_IMAGE@@"
    container_name: "conductor-s3-@@S3_CONTAINER_NAME@@"
    restart: unless-stopped
    ports:
      - "127.0.0.1:@@S3_PORT@@:7070"
    environment:
      ROOT_ACCESS_KEY: "@@S3_ACCESS_KEY@@"
      ROOT_SECRET_KEY: "@@S3_SECRET_KEY@@"
      VGW_BACKEND: "posix"
      VGW_BACKEND_ARG: "/data"
      VGW_PORT: ":7070"
    volumes:
      - "@@S3_DATA_PATH@@:/data:rw"
