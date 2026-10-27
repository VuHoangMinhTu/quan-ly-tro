// Produce a display-only crop. Never replace the uploaded image or invoice snapshot.
export async function cropPaymentQr(blob) {
  let bitmap
  try {
    bitmap = await createImageBitmap(blob)
    const { default: decode } = await import('jsqr')
    const scale = Math.min(1, 1280 / Math.max(bitmap.width, bitmap.height))
    const scan = document.createElement('canvas')
    scan.width = Math.max(1, Math.round(bitmap.width * scale))
    scan.height = Math.max(1, Math.round(bitmap.height * scale))
    const context = scan.getContext('2d', { willReadFrequently: true })
    context.fillStyle = '#fff'
    context.fillRect(0, 0, scan.width, scan.height)
    context.drawImage(bitmap, 0, 0, scan.width, scan.height)
    const code = decode(context.getImageData(0, 0, scan.width, scan.height).data, scan.width, scan.height)
    if (!code) return blob

    const corners = ['topLeftCorner', 'topRightCorner', 'bottomLeftCorner', 'bottomRightCorner'].map(key => code.location[key])
    const xScale = bitmap.width / scan.width, yScale = bitmap.height / scan.height
    const left = Math.max(0, Math.floor(Math.min(...corners.map(point => point.x)) * xScale) - 2)
    const top = Math.max(0, Math.floor(Math.min(...corners.map(point => point.y)) * yScale) - 2)
    const right = Math.min(bitmap.width, Math.ceil(Math.max(...corners.map(point => point.x)) * xScale) + 2)
    const bottom = Math.min(bitmap.height, Math.ceil(Math.max(...corners.map(point => point.y)) * yScale) + 2)
    const width = right - left, height = bottom - top
    const size = Math.max(width, height)
    // Four modules of quiet zone, plus one module for detection/rounding tolerance.
    const padding = Math.ceil(size / (17 + 4 * code.version) * 5)
    const outputScale = Math.min(1, 1280 / (size + padding * 2))
    const crop = document.createElement('canvas')
    crop.width = crop.height = Math.ceil((size + padding * 2) * outputScale)
    const output = crop.getContext('2d', { willReadFrequently: true })
    output.fillStyle = '#fff'
    output.fillRect(0, 0, crop.width, crop.height)
    output.drawImage(bitmap, left, top, width, height, (padding + (size - width) / 2) * outputScale, (padding + (size - height) / 2) * outputScale, width * outputScale, height * outputScale)

    // A crop is accepted only if its decoded bytes match the original QR exactly.
    const verified = decode(output.getImageData(0, 0, crop.width, crop.height).data, crop.width, crop.height)
    if (!verified || code.binaryData.length !== verified.binaryData.length || code.binaryData.some((byte, index) => byte !== verified.binaryData[index])) return blob
    return await new Promise(resolve => crop.toBlob(result => resolve(result || blob), 'image/png'))
  } catch {
    // Unsupported browser, unreadable image, or no reliable QR: preserve the source.
    return blob
  } finally {
    bitmap?.close()
  }
}
