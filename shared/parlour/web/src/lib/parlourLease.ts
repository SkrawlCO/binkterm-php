/**
 * Re-exports the shared caller-scoped persistence layer (Slice 4) for the
 * Web adapter. `shared/parlour/persistence/` is not a separate vendored
 * package — it is L33TEST-authored orchestration living one level up in
 * the same `shared/parlour/` tree the vendored facade already does (see
 * `facade/index.ts`'s own relative import of `../vendor/packages/engine`).
 * Centralized here so every Web page imports one path (`@/lib/parlourLease`)
 * rather than repeating the relative traversal.
 */
export * from '../../../persistence/client';
export * from '../../../persistence/envelope';
export * from '../../../facade/index';
export { CATALOG, catalogEntry, groupedCatalog, type CatalogEntry, type Family } from '../../../terminal/src/catalog';
