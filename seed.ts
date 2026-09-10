import { createHash, randomUUID } from 'crypto';
import { PrismaClient } from '@prisma/client';
import { PrismaPg } from '@prisma/adapter-pg';
import { Pool } from 'pg';
import dotenv from 'dotenv';

import type { Prisma } from '@prisma/client';

import { SEED_IDS } from './seed-ids';
import { allocateUniqueSku } from '../services/sku.service';

dotenv.config();

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
});

const adapter = new PrismaPg(pool);
const prisma = new PrismaClient({ adapter });

const SHOP_STATUS_APPROVED = 1;

/** Type for shop objects with optional userId and userName */
type ShopSeedData = {
  slug: string;
  name: string;
  description: string;
  userId?: string;
  userName?: string;
};

/** RFC-4122-shaped UUID deterministic from key (reproducible catalog IDs). */
function stableUuid(key: string): string {
  const digest = createHash('sha256').update(`itv-product-seed:${key}`, 'utf8').digest();
  const bytes = Buffer.allocUnsafe(16);
  digest.copy(bytes, 0, 0, 16);
  bytes[6] = (bytes[6]! & 0x0f) | 0x40;
  bytes[8] = (bytes[8]! & 0x3f) | 0x80;
  const h = bytes.toString('hex');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20, 32)}`;
}

function mulberry32(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a += 0x6d2b79f5;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function shuffle<T>(arr: T[], rng: () => number): T[] {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i -= 1) {
    const j = Math.floor(rng() * (i + 1));
    const x = a[i];
    a[i] = a[j]!;
    a[j] = x!;
  }
  return a;
}

const PRODUCTS_PER_CATEGORY = 10;
const VARIANT_MIN = 1;
const VARIANT_MAX = 4;

const COLOR_CODES = ['BLK', 'WHT', 'RED', 'NVY'] as const;
const SIZE_CODES = ['S', 'M', 'L', 'XL', 'US7', 'US8'] as const;

const SEED_CATEGORIES = [
  { slug: 'clothing', name: 'Clothing', skuPrefix: 'CLOTH', description: 'Apparel' },
  { slug: 'electronics', name: 'Electronics', skuPrefix: 'ELEC', description: 'Gadgets' },
  { slug: 'footwear', name: 'Footwear', skuPrefix: 'FOOT', description: 'Shoes' },
  { slug: 'home-kitchen', name: 'Home & Kitchen', skuPrefix: 'HOME', description: 'Home' },
  { slug: 'beauty', name: 'Beauty', skuPrefix: 'BEAU', description: 'Beauty' },
  { slug: 'sports', name: 'Sports', skuPrefix: 'SPRT', description: 'Sports' },
  { slug: 'books', name: 'Books', skuPrefix: 'BOOK', description: 'Books' },
  { slug: 'toys', name: 'Toys', skuPrefix: 'TOYS', description: 'Toys' },
  { slug: 'automotive', name: 'Automotive', skuPrefix: 'AUTO', description: 'Auto' },
  { slug: 'garden', name: 'Garden', skuPrefix: 'GRDN', description: 'Garden' },

/** Type for shop objects with optional userId and userName */
type ShopSeedData = {
  slug: string;
  name: string;
  description: string;
  userId?: string;
  userName?: string;
};
  { slug: 'jewelry', name: 'Jewelry', skuPrefix: 'JWLR', description: 'Jewelry' },
  { slug: 'pet-supplies', name: 'Pet Supplies', skuPrefix: 'PET', description: 'Pets' },
] as const;

const CATEGORY_COUNT = SEED_CATEGORIES.length;

const SHOP_COUNT = 10;

/** Ten approved shops; products are round-robin assigned, with a duplicate listing on the next shop. */
const SEED_SHOPS: ShopSeedData[] = [
  { slug: 'main-store', name: 'Main Store', description: 'Primary demo shop', userId: 'ce8dc9fc-542e-4e26-8b4a-c3fd97d5b20c', userName: 'Demo Shop Admin' },
  { slug: 'outlet-store', name: 'Outlet Store', description: 'Secondary demo shop', userId: 'ce8dc9fc-542e-4e26-8b4a-c3fd97d5b20c', userName: 'Demo Shop Admin' },
  { slug: 'seed-shop-03', name: 'North Hub', description: 'Northern warehouse', userId: 'ce8dc9fc-542e-4e26-8b4a-c3fd97d5b20c', userName: 'Demo Shop Admin' },
  { slug: 'seed-shop-04', name: 'South Hub', description: 'Southern warehouse' },
  { slug: 'seed-shop-05', name: 'East Market', description: 'East region store' },
  { slug: 'seed-shop-06', name: 'West Market', description: 'West region store' },
  { slug: 'seed-shop-07', name: 'Central Plaza', description: 'Central shopping' },
  { slug: 'seed-shop-08', name: 'Harbor Depot', description: 'Harbor logistics' },
  { slug: 'seed-shop-09', name: 'Highland Shop', description: 'Highland branch' },
  { slug: 'seed-shop-10', name: 'Valley Outlet', description: 'Valley outlet' },
];

const BRAND_SLUGS = ['acme-co', 'globex', 'nike', 'apple', 'samsung'] as const;

const MARKER_PRODUCT_SLUG = 'seed-c-00-p-00';

if (CATEGORY_COUNT !== 12) {
  throw new Error(`Expected 12 categories, got ${CATEGORY_COUNT}`);
}

if (SEED_SHOPS.length !== SHOP_COUNT) {
  throw new Error(`Expected ${SHOP_COUNT} shops, got ${SEED_SHOPS.length}`);
}

async function seedReferenceData(): Promise<void> {
  const brandRows = [
    {
      id: SEED_IDS.brands.acme,
      code: 'ACM',
      name: 'Acme Co',
      slug: 'acme-co',
    },
    {
      id: SEED_IDS.brands.globex,
      code: 'GLB',
      name: 'Globex',
      slug: 'globex',
    },
    {
      id: SEED_IDS.brands.nike,
      code: 'NIK',
      name: 'Nike',
      slug: 'nike',
    },
    {
      id: SEED_IDS.brands.apple,
      code: 'APL',
      name: 'Apple',
      slug: 'apple',
    },
    {
      id: SEED_IDS.brands.samsung,
      code: 'SAM',
      name: 'Samsung',
      slug: 'samsung',
    },
 ];

  for (const b of brandRows) {
    await prisma.brand.upsert({
      where: { slug: b.slug },
      update: { code: b.code, name: b.name },
      create: { id: b.id, code: b.code, name: b.name, slug: b.slug },
    });
  }

  const colors = [
    { id: SEED_IDS.colors.black, code: 'BLK', name: 'Black' },
    { id: SEED_IDS.colors.white, code: 'WHT', name: 'White' },
    { id: SEED_IDS.colors.red, code: 'RED', name: 'Red' },
    { id: SEED_IDS.colors.navy, code: 'NVY', name: 'Navy' },
 ];

  for (const c of colors) {
    await prisma.color.upsert({
      where: { code: c.code },
      update: { name: c.name },
      create: { id: c.id, code: c.code, name: c.name },
    });
  }

  const sizes = [
    { id: SEED_IDS.sizes.s, code: 'S', name: 'Small' },
    { id: SEED_IDS.sizes.m, code: 'M', name: 'Medium' },
    { id: SEED_IDS.sizes.l, code: 'L', name: 'Large' },
    { id: SEED_IDS.sizes.xl, code: 'XL', name: 'Extra Large' },
    { id: SEED_IDS.sizes.us7, code: 'US7', name: 'US 7' },
    { id: SEED_IDS.sizes.us8, code: 'US8', name: 'US 8' },
 ];

  for (const s of sizes) {
    await prisma.size.upsert({
      where: { code: s.code },
      update: { name: s.name },
      create: { id: s.id, code: s.code, name: s.name },
    });
  }

  for (const cat of SEED_CATEGORIES) {
    await prisma.category.upsert({
      where: { slug: cat.slug },
      update: {
        skuPrefix: cat.skuPrefix,
        description: cat.description,
        image: '/uploads/categories/mobile1-1768495484496-873186350.png',
      },
      create: {
        id: stableUuid(`category:${cat.slug}`),
        name: cat.name,
        slug: cat.slug,
        skuPrefix: cat.skuPrefix,
        description: cat.description,
        image: '/uploads/categories/mobile1-1768495484496-873186350.png',
      },
    });
  }

  for (const shop of SEED_SHOPS) {
    await prisma.shop.upsert({
      where: { slug: shop.slug },
      update: {
        name: shop.name,
        shopStatusId: SHOP_STATUS_APPROVED,
        isActive: true,
        ...(shop.userId ? { userId: shop.userId, userName: shop.userName } : {}),
      },
      create: {
        id: stableUuid(`shop:${shop.slug}`),
        name: shop.name,
        slug: shop.slug,
        description: shop.description,
        shopStatusId: SHOP_STATUS_APPROVED,
        isActive: true,
        ...(shop.userId ? { userId: shop.userId, userName: shop.userName } : {}),
      },
    });
  }
}

type VariantPlan = {
  id: string;
  colorId: string;
  sizeId: string;
  basePrice: number;
  stock: number;
};

async function syncProductDenormalizedFields(
  tx: Prisma.TransactionClient,
  productId: string
): Promise<void> {
  const variants = await tx.productVariant.findMany({
    where: { productId },
    orderBy: [{ isPrimary: 'desc' }, { createdAt: 'asc' }],
  });
  if (variants.length === 0) {
    return;
  }
  const primary = variants.find((v) => v.isPrimary) ?? variants[0];
  const totalStock = variants.reduce((s, v) => s + v.stock, 0);
  await tx.product.update({
    where: { id: productId },
    data: {
      sku: primary.sku,
      price: primary.effectiveSellingPrice,
      stock: totalStock,
      primaryVariantId: primary.id,
    },
  });
}

async function seedProductWithVariants(params: {
  productId: string;
  name: string;
  slug: string;
  description: string;
  categoryId: string;
  shopId: string;
  brandId: string;
  images: string[];
  primaryVariantIndex: number;
  variants: VariantPlan[];
}): Promise<void> {
  const category = await prisma.category.findUniqueOrThrow({
    where: { id: params.categoryId },
  });
  const brand = await prisma.brand.findUniqueOrThrow({
    where: { id: params.brandId },
  });

  await prisma.$transaction(async (tx) => {
    const created = await tx.product.create({
      data: {
        id: params.productId,
        name: params.name,
        description: params.description,
        slug: params.slug,
        categoryId: params.categoryId,
        shopId: params.shopId,
        brandId: params.brandId,
        images: params.images,
        isActive: true,
        sku: `TMP-${randomUUID().replace(/-/g, '')}`,
        price: 0,
        stock: 0,
        warehouseStockMirror: 0,
      },
    });

    for (let i = 0; i < params.variants.length; i += 1) {
      const v = params.variants[i];
      const color = await tx.color.findUniqueOrThrow({ where: { id: v.colorId } });
      const size = await tx.size.findUniqueOrThrow({ where: { id: v.sizeId } });

      const sku = await allocateUniqueSku(tx, {
        shopId: params.shopId,
        categoryId: params.categoryId,
        ctx: {
          category: { slug: category.slug, skuPrefix: category.skuPrefix },
          brand: { code: brand.code },
          color: { code: color.code },
          size: { code: size.code },
        },
      });

      await tx.productVariant.create({
        data: {
          id: v.id,
          productId: created.id,
          colorId: v.colorId,
          sizeId: v.sizeId,
          sku,
          basePrice: v.basePrice,
          profitThresholdPercent: 0,
          discountPercent: 0,
          effectiveSellingPrice: v.basePrice,
          stock: v.stock,
          isPrimary: i === params.primaryVariantIndex,
        },
      });
    }

    await syncProductDenormalizedFields(tx, created.id);
  });
}

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

function seedKey(catIdx: number, prodIdx: number): number {
  return ((catIdx + 1) * 1_049_311 + (prodIdx + 1) * 10_007) >>> 0;
}

type ColorSizePair = {
  c: (typeof COLOR_CODES)[number];
  s: (typeof SIZE_CODES)[number];
};

function buildVariantPlansForCell(
  ci: number,
  pi: number,
  allPairs: ColorSizePair[],
  colorId: (code: (typeof COLOR_CODES)[number]) => string,
  sizeId: (code: (typeof SIZE_CODES)[number]) => string,
): VariantPlan[] {
  const rng = mulberry32(seedKey(ci, pi));
  const variantCount =
    VARIANT_MIN + Math.floor(rng() * (VARIANT_MAX - VARIANT_MIN + 1));
  const chosen = shuffle(allPairs, rng).slice(0, variantCount);
  return chosen.map((pair, vi) => ({
    id: stableUuid(`variant:${ci}:${pi}:${vi}`),
    colorId: colorId(pair.c),
    sizeId: sizeId(pair.s),
    basePrice:
      Math.round(
        (9.99 + ci * 3 + pi * 2 + rng() * 40 + vi * 0.5) * 100,
      ) / 100,
    stock: 8 + Math.floor(rng() * 42),
  }));
}

function cloneVariantPlansForDuplicateShop(
  plans: VariantPlan[],
  ci: number,
  pi: number,
): VariantPlan[] {
  return plans.map((v, vi) => ({
    ...v,
    id: stableUuid(`variant:${ci}:${pi}:${vi}:dup`),
  }));
}

async function seedGeneratedCatalog(): Promise<void> {
  const existing = await prisma.product.findUnique({
    where: { slug: MARKER_PRODUCT_SLUG },
  });
  if (existing) {
    console.log(`Catalog seed already present (${MARKER_PRODUCT_SLUG}). Skipping generated products.`);
    return;
  }

  const shops = await Promise.all(
    SEED_SHOPS.map((s) => prisma.shop.findUniqueOrThrow({ where: { slug: s.slug } })),
  );

  const colorRows = await prisma.color.findMany({
    where: { code: { in: [...COLOR_CODES] } },
  });
  const sizeRows = await prisma.size.findMany({
    where: { code: { in: [...SIZE_CODES] } },
  });
  const colorMap = new Map(colorRows.map((c) => [c.code, c.id]));
  const sizeMap = new Map(sizeRows.map((s) => [s.code, s.id]));

  const colorId = (code: (typeof COLOR_CODES)[number]): string => {
    const id = colorMap.get(code);
    if (!id) {
      throw new Error(`Seed: missing color ${code}`);
    }
    return id;
  };

  const sizeId = (code: (typeof SIZE_CODES)[number]): string => {
    const id = sizeMap.get(code);
    if (!id) {
      throw new Error(`Seed: missing size ${code}`);
    }
    return id;
  };

  const brandRows = await prisma.brand.findMany({
    where: { slug:  { in: [...BRAND_SLUGS] } },
  });
  const brandBySlug = new Map(brandRows.map((b) => [b.slug, b.id]));
  const brandIds = BRAND_SLUGS.map((s) => {
    const id = brandBySlug.get(s);
    if (!id) {
      throw new Error(`Seed: missing brand ${s}`);
    }
    return id;
  });

  const categories = await Promise.all(
    SEED_CATEGORIES.map((c) =>
      prisma.category.findUniqueOrThrow({ where: { slug: c.slug } }),
    ),
  );

  const allPairs: ColorSizePair[] = [];
  for (const c of COLOR_CODES) {
    for (const s of SIZE_CODES) {
      allPairs.push({ c, s });
    }
  }

  let created = 0;

  for (let ci = 0; ci < CATEGORY_COUNT; ci += 1) {
    const category = categories[ci];
    if (!category) {
      throw new Error(`Missing category index ${ci}`);
    }
    for (let pi = 0; pi < PRODUCTS_PER_CATEGORY; pi += 1) {
      const linear = ci * PRODUCTS_PER_CATEGORY + pi;
      const shopIdx = linear % SHOP_COUNT;
      const dupShopIdx = (shopIdx + 1) % SHOP_COUNT;
      const shopId = shops[shopIdx]!.id;
      const dupShopId = shops[dupShopIdx]!.id;

      const slug = `seed-c-${pad2(ci)}-p-${pad2(pi)}`;
      const name = `${SEED_CATEGORIES[ci].name} sample ${pi + 1}`;
      const brandId = brandIds[linear % brandIds.length]!;
      const productId = stableUuid(`product:${ci}:${pi}`);

      const variantsPrimary = buildVariantPlansForCell(
        ci,
        pi,
        allPairs,
        colorId,
        sizeId,
      );

      const baseDescription = `Seeded item for ${SEED_CATEGORIES[ci].name} (category ${ci + 1}/${CATEGORY_COUNT}, product ${pi + 1}/${PRODUCTS_PER_CATEGORY}). Shop slot ${shopIdx + 1}/${SHOP_COUNT}.`;

      await seedProductWithVariants({
        productId,
        name,
        slug,
        description: baseDescription,
        categoryId: category.id,
        shopId,
        brandId,
        images: ['/uploads/products/laptop2.jpeg'],
        primaryVariantIndex: 0,
        variants: variantsPrimary,
      });
      created += 1;

      const variantsDup = cloneVariantPlansForDuplicateShop(
        variantsPrimary,
        ci,
        pi,
      );

      await seedProductWithVariants({
        productId: stableUuid(`product:${ci}:${pi}:dup`),
        name,
        slug: `${slug}-dup`,
        description: `${baseDescription} Duplicate listing (same variants/price intent) in another shop.`,
        categoryId: category.id,
        shopId: dupShopId,
        brandId,
        images: ['/uploads/products/laptop2.jpeg'],
        primaryVariantIndex: 0,
        variants: variantsDup,
      });
      created += 1;
    }
  }

  console.log(
    `Created ${created} product rows (${CATEGORY_COUNT} categories × ${PRODUCTS_PER_CATEGORY} × 2: primary shop + duplicate shop).`,
  );
}

async function main(): Promise<void> {
  console.log(
    `Seeding reference data + catalog (${SHOP_COUNT} shops, ${CATEGORY_COUNT} categories, ${PRODUCTS_PER_CATEGORY} base products per category, duplicate per next shop, ${VARIANT_MIN}–${VARIANT_MAX} variants)…`,
  );

  await seedReferenceData();
  await seedGeneratedCatalog();

  console.log('Seeding completed.');
  console.log('Next: npm run events:replay-products (and run outbox publisher) to sync read models.');
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
    await pool.end();
  });
