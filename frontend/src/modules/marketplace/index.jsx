import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { MapPin, Search, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { ListingGrid, MunicipalityFilter, SpeciesTileImage, VarietyFilter } from '../../components/listings.jsx'
import { EmptyState } from '../../components/states.jsx'
import { Avatar, Section, Stat, Step } from '../../components/ui.jsx'
import { api } from '../../lib/api.js'
import { SPECIES_OPTIONS } from '../../lib/constants.js'
import { mapListing, mapSeller, matchesSpeciesFilter } from '../../lib/listing.js'
import { sellerProfilePath } from '../../lib/session.js'



export function LandingPage() {
  const listingsQuery = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const sellersQuery = useQuery({
    queryKey: ['sellers'],
    queryFn: async () => (await api.get('/sellers')).data.map(mapSeller),
    retry: false,
    placeholderData: [],
  })
  // Coverage, not the seeded dropdown list: only municipalities with an active
  // LGU Admin come back, so a visitor can tell whether their own area is served
  // before they bother registering.
  const partnersQuery = useQuery({
    queryKey: ['partner-municipalities'],
    queryFn: async () => (await api.get('/partner-municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  const partners = partnersQuery.data || []
  const featured = listingsQuery.data || []
  const featuredSellers = sellersQuery.data || []
  const verifiedSellerCount = featuredSellers.filter((seller) => seller.verified).length

  return (
    <main>
      <section className="hero">
        <div className="hero-copy">
          <p className="eyebrow">Government-supported aquaculture marketplace</p>
          <h1>Fresh fish fingerlings from verified local hatcheries.</h1>
          <p className="lead">Search species, compare sellers, coordinate orders, pay through PayMongo, and get farming guidance from Gemini AI.</p>
          <div className="hero-search"><Search size={20} /><input placeholder="Search Bangus, Tilapia, Mandaue..." /><Link className="button" to="/browse">Search</Link></div>
          <div className="hero-actions"><Link className="button" to="/register">Start buying</Link><Link className="ghost" to="/register">Register hatchery</Link></div>
        </div>
        <div className="market-panel">
          <Stat value={featured.length} label="Active listings" />
          <Stat value={verifiedSellerCount} label="Verified sellers" />
          <Stat value="Escrow" label="Secure PayMongo checkout" />
        </div>
      </section>
      <Section title="Featured Listings">
        {featured.length ? <ListingGrid items={featured.slice(0, 3)} /> : <EmptyState message="No listings available yet. Check back soon as verified sellers add their stock." />}
      </Section>
      <Section title="Featured Sellers">
        {featuredSellers.length ? <SellerGrid items={featuredSellers.slice(0, 3)} /> : <EmptyState message="No sellers registered yet." />}
      </Section>
      {/* Coverage map in list form. A visitor's first question is "is my town
          on here?", and until now the only way to find out was to start
          registering and open the municipality dropdown -- which lists every
          seeded municipality, partnered or not. */}
      <Section title="Partnered LGUs">
        <p className="helper-text">
          These municipalities have an LGU partner on AbaiMarket. Their LGU verifies local hatcheries, approves seller earnings, and handles reports for the area.
        </p>
        {partners.length ? (
          <div className="lgu-grid">
            {partners.map((partner) => (
              <div className="lgu-card" key={partner.id}>
                <span className="lgu-card-head">
                  <MapPin size={16} />
                  <strong>{partner.name}</strong>
                </span>
                <span className="muted">{partner.province}</span>
                <span className="lgu-card-count">
                  {partner.verified_sellers_count
                    ? `${partner.verified_sellers_count} verified ${partner.verified_sellers_count === 1 ? 'seller' : 'sellers'}`
                    : 'No verified sellers yet'}
                </span>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState message="No LGU partners yet. Municipalities appear here once their LGU joins AbaiMarket." />
        )}
      </Section>
      <Section title="How It Works"><div className="steps"><Step n="1" t="Register" d="Buyers and sellers create verified marketplace accounts." /><Step n="2" t="Order & Pay" d="Buyers place orders and pay through PayMongo Checkout." /><Step n="3" t="LGU Oversight" d="LGU admins verify sellers and approve local listings." /><Step n="4" t="Release" d="Super Admin releases held seller funds after completion." /></div></Section>
      {/* Browse-by-species row: the photo leads, the label sits under it, and
          each tile lands on Browse already filtered to that species. */}
      <Section title="Shop by Species">
        <div className="species-row">
          {SPECIES_OPTIONS.map((species) => (
            <Link className="species-tile" key={species} to={`/browse?species=${encodeURIComponent(species)}`}>
              <SpeciesTileImage species={species} listings={featured} />
              <strong>{species}</strong>
            </Link>
          ))}
        </div>
      </Section>
      <AboutPage compact />
    </main>
  )
}

export function BrowsePage() {
  const [searchParams] = useSearchParams()
  // Honour ?species= so the landing page's Shop by Species tiles arrive
  // pre-filtered instead of dropping the visitor into the full catalogue.
  const requestedSpecies = searchParams.get('species')
  const [filters, setFilters] = useState({
    q: '',
    species: SPECIES_OPTIONS.includes(requestedSpecies) ? requestedSpecies : 'All',
    variety: 'All',
    municipality: 'All',
  })
  const { data = [] } = useQuery({
    queryKey: ['listings'],
    queryFn: async () => (await api.get('/listings')).data.map(mapListing),
    retry: false,
    placeholderData: [],
  })
  const filtered = data.filter((item) => {
    const haystack = `${item.title} ${item.species} ${item.variety || ''} ${item.seller} ${item.municipality}`.toLowerCase()
    return haystack.includes(filters.q.toLowerCase()) && matchesSpeciesFilter(item, filters) && (filters.municipality === 'All' || item.municipality === filters.municipality)
  })
  return (
    <main className="page-grid">
      <aside className="filter-card">
        <h2>Advanced Filters</h2>
        <label className="filter-label">Search<input placeholder="Search listings" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></label>
        <label className="filter-label">Species<select value={filters.species} onChange={(e) => setFilters({ ...filters, species: e.target.value, variety: 'All' })}><option>All</option>{SPECIES_OPTIONS.map((s) => <option key={s}>{s}</option>)}</select></label>
        <VarietyFilter listings={data} filters={filters} setFilters={setFilters} />
        <MunicipalityFilter listings={data} filters={filters} setFilters={setFilters} />
      </aside>
      {filtered.length ? <ListingGrid items={filtered} /> : <EmptyState message="No listings match your filters yet." />}
    </main>
  )
}

export function SellersPage() {
  const { data = [] } = useQuery({
    queryKey: ['sellers'],
    queryFn: async () => (await api.get('/sellers')).data.map(mapSeller),
    retry: false,
    placeholderData: [],
  })
  return <main><Section title="Verified Sellers">{data.length ? <SellerGrid items={data} /> : <EmptyState message="No sellers registered yet." />}</Section></main>
}

function SellerGrid({ items = [] }) {
  return (
    <div className="seller-grid">
      {items.map((seller) => (
        <Link className="card seller" to={sellerProfilePath(seller.id)} key={seller.id || seller.name}>
          <Avatar src={seller.profile_picture} alt={seller.name} className="seller-grid-avatar" />
          {seller.verified && <ShieldCheck size={16} className="seller-grid-badge" />}
          <h3>{seller.name}</h3>
          <p>{seller.municipality}</p>
          <strong>{seller.rating}/5</strong>
          <span>{seller.listings} listings</span>
        </Link>
      ))}
    </div>
  )
}

export function AboutPage({ compact = false }) {
  const about = (
    <Section title="About the Platform">
      <div className="about-card">
        <p>
          AbaiMarket is a marketplace for fish fingerlings from local hatcheries in Cebu. Buyers order from hatcheries that their own LGU has
          verified and pay through PayMongo, and the payment is held until the order is done right.
        </p>
        <p>
          Sellers post listings that go live right away, manage their orders, and withdraw their earnings. LGU admins oversee the sellers in
          their municipality, and the AbaiMarket team handles payments and payouts. Messaging, reviews, notifications, Help &amp; Support and an
          AI assistant for fish-farming questions are built in.
        </p>
        {!compact && (
          <div className="about-actions">
            <Link className="button" to="/register">Join AbaiMarket</Link>
            <Link className="ghost" to="/help">Visit the Help Center</Link>
          </div>
        )}
      </div>
    </Section>
  )

  // On the landing page this is one section inside that page's own <main>;
  // as the /about route it is the whole page and needs the wrapper itself.
  return compact ? about : <main className="about-page">{about}</main>
}