export function clearContractDocumentCache(client) {
  // Final documents and template bodies may contain private tenant/legal content.
  for (const prefix of ['contract-document', 'contract-document-preview', 'contract-template', 'contract-templates', 'contract-signing']) {
    client.removeQueries({ queryKey: [prefix] })
  }
}
