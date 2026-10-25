import { readdirSync, readFileSync, statSync } from 'node:fs'
import { resolve, relative } from 'node:path'
import { gzipSync, brotliCompressSync } from 'node:zlib'

const root = resolve(process.argv[2] || 'tmp/performance-audit-dist')
function files(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap(item => item.isDirectory() ? files(resolve(dir, item.name)) : [resolve(dir, item.name)])
}
const all = files(root)
const assets = all.filter(path => /\.(js|css|woff2?|png)$/.test(path)).map(path => {
  const bytes = readFileSync(path)
  return { file: relative(root, path), bytes: bytes.length, gzip: gzipSync(bytes).length, brotli: brotliCompressSync(bytes).length }
})
console.log(JSON.stringify({
  root, jsChunks: assets.filter(asset => asset.file.endsWith('.js')).length,
  assets, totalEmittedBytes: all.reduce((sum, path) => sum + statSync(path).size, 0),
  note: 'Compression is computed locally, not evidence that Nginx serves gzip or Brotli. Emitted fonts are not all necessarily downloaded.',
}, null, 2))
