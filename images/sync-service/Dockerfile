FROM golang:1.25-rc-bullseye AS builder

# Install build dependencies for CGO
RUN apt-get update && apt-get install -y \
    gcc \
    libc6 \
    g++ \
    make \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY go.mod go.sum ./
RUN go mod download

COPY . .

# Enable CGO for reindexer cproto binding
ENV CGO_ENABLED=1
ENV GOOS=linux

RUN go build -o sync-service -ldflags="-w -s" .

# Runtime stage
FROM debian:bullseye-slim

# Install runtime dependencies
RUN apt-get update && apt-get install -y \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=builder /app/sync-service .

CMD ["./sync-service"]