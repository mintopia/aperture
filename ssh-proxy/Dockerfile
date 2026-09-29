FROM golang:alpine AS builder
WORKDIR /build
COPY go.mod go.sum ./
RUN go mod download
COPY . .
ARG VERSION=dev
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w -X sshproxy/handler.Version=${VERSION}" -o ssh-proxy .

FROM alpine:3.21
RUN apk add --no-cache ca-certificates wget
COPY --from=builder /build/ssh-proxy /usr/local/bin/ssh-proxy
EXPOSE 8022
ENTRYPOINT ["/usr/local/bin/ssh-proxy"]
