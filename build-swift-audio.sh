#!/bin/bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE="$PROJECT_ROOT/native/macos-audio-capture/AudioCapture.swift"
OUTPUT="$PROJECT_ROOT/native/macos-audio-capture/macos-audio-capture"
BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/clueless-swift-audio.XXXXXX")"
SDK_PATH="$(xcrun --sdk macosx --show-sdk-path)"
export CLANG_MODULE_CACHE_PATH="$BUILD_DIR/clang-module-cache"
export SWIFT_MODULE_CACHE_PATH="$BUILD_DIR/swift-module-cache"

cleanup() {
    rm -rf "$BUILD_DIR"
}
trap cleanup EXIT

build_architecture() {
    local architecture="$1"
    local target="$architecture-apple-macos13.0"
    local architecture_output="$BUILD_DIR/macos-audio-capture-$architecture"

    echo "Building Swift audio helper for $architecture..."
    xcrun swiftc "$SOURCE" \
        -o "$architecture_output" \
        -sdk "$SDK_PATH" \
        -framework ScreenCaptureKit \
        -framework CoreAudio \
        -framework AVFoundation \
        -framework AppKit \
        -O \
        -target "$target" \
        -swift-version 5
}

build_architecture arm64
build_architecture x86_64

xcrun lipo -create \
    "$BUILD_DIR/macos-audio-capture-arm64" \
    "$BUILD_DIR/macos-audio-capture-x86_64" \
    -output "$OUTPUT"

chmod +x "$OUTPUT"

echo "Built universal Swift audio helper:"
echo "  $OUTPUT"
xcrun lipo -archs "$OUTPUT"
