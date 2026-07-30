export function bytesToBase64(bytes: Uint8Array): string {
    let binary = '';
    const chunkSize = 0x8000;
    for (let offset = 0; offset < bytes.length; offset += chunkSize) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
    }
    return btoa(binary);
}

export function int16ToBase64(input: Int16Array): string {
    return bytesToBase64(new Uint8Array(input.buffer, input.byteOffset, input.byteLength));
}
