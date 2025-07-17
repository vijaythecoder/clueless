#!/bin/bash

# Build script for macOS audio capture Swift executable

echo "Building macOS audio capture executable..."

# Create build directory if it doesn't exist
mkdir -p build/native

# Compile Swift code
swiftc native/macos-audio-capture/AudioCapture.swift \
    -o build/native/macos-audio-capture \
    -framework ScreenCaptureKit \
    -framework CoreAudio \
    -framework AVFoundation \
    -O \
    -target arm64-apple-macos13.0 \
    -swift-version 5

# Check if build succeeded
if [ $? -eq 0 ]; then
    echo "✅ Swift audio capture built successfully"
    echo "Executable location: build/native/macos-audio-capture"
    # Make sure it's executable
    chmod +x build/native/macos-audio-capture
    
    # Attempt to sign the binary with available certificate
    echo "🔐 Attempting to sign Swift audio capture binary..."
    
    # Try to find a Developer ID Application certificate first (for distribution)
    DIST_CERT=$(security find-identity -v -p codesigning | grep "Developer ID Application" | head -1 | cut -d'"' -f2)
    
    # If no distribution cert, try Development certificate (for local testing)
    if [ -z "$DIST_CERT" ]; then
        DEV_CERT=$(security find-identity -v -p codesigning | grep "Apple Development" | head -1 | cut -d'"' -f2)
        CERT_NAME="$DEV_CERT"
        CERT_TYPE="Development"
    else
        CERT_NAME="$DIST_CERT"
        CERT_TYPE="Distribution"
    fi
    
    if [ -n "$CERT_NAME" ]; then
        echo "📋 Using $CERT_TYPE certificate: $CERT_NAME"
        codesign --force --sign "$CERT_NAME" \
            --options runtime \
            --entitlements native/macos-audio-capture/entitlements.plist \
            build/native/macos-audio-capture
        
        if [ $? -eq 0 ]; then
            echo "✅ Swift audio capture signed successfully with $CERT_TYPE certificate"
            
            # Also sign the extras directory binary if it exists
            if [ -f "extras/macos-audio-capture" ]; then
                echo "🔐 Signing extras audio capture binary..."
                codesign --force --sign "$CERT_NAME" \
                    --options runtime \
                    --entitlements native/macos-audio-capture/entitlements.plist \
                    extras/macos-audio-capture
                
                if [ $? -eq 0 ]; then
                    echo "✅ Extras audio capture signed successfully"
                else
                    echo "⚠️  Warning: Failed to sign extras audio capture (continuing anyway)"
                fi
            fi
        else
            echo "⚠️  Warning: Failed to sign Swift audio capture (continuing anyway)"
        fi
    else
        echo "⚠️  No code signing certificate found - creating unsigned build"
        echo "💡 To create signed builds, install an Apple Developer certificate in Keychain"
        echo "   For distribution: 'Developer ID Application' certificate"
        echo "   For development: 'Apple Development' certificate"
    fi
else
    echo "❌ Failed to build Swift audio capture"
    exit 1
fi