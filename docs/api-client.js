// Client minimal de l'API, à copier dans le projet React (ex. src/services/api.js).
// Même vocabulaire que le store actuel : products, categories, orders, content, slider.
// .env du React :  VITE_API_URL=http://localhost:8000

const BASE = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/$/, '');
const TOKEN_KEY = 'dab-token';

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message ?? 'Erreur réseau');
    this.status = status;
    this.code = body?.error;              // ex. "validation_failed", "email_not_verified", "invalid_credentials"
    this.violations = body?.violations ?? {}; // { name: "Saisissez votre nom." } -> à afficher sous chaque champ
  }
}

export const getToken = () => localStorage.getItem(TOKEN_KEY);
const setToken = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY));

async function request(path, { method = 'GET', body, query, form } = {}) {
  const url = new URL(BASE + path);
  Object.entries(query ?? {}).forEach(([k, v]) => v !== undefined && v !== '' && url.searchParams.set(k, v));
  const headers = { Accept: 'application/json' };
  if (getToken()) headers.Authorization = `Bearer ${getToken()}`;
  if (body) headers['Content-Type'] = 'application/json';

  let res;
  try {
    res = await fetch(url, { method, headers, body: form ?? (body ? JSON.stringify(body) : undefined) });
  } catch {
    throw new ApiError(0, { message: 'Serveur injoignable.' });
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

export const api = {
  // --- Authentification
  register: (d) => request('/api/auth/register', { method: 'POST', body: d }),      // { name, email, password, confirm, consent: true }
  resendVerification: (email) => request('/api/auth/resend-verification', { method: 'POST', body: { email } }),
  verifyEmail: (token) => request('/api/auth/verify-email', { method: 'POST', body: { token } }),
  async login(email, password) {
    const res = await request('/api/auth/login', { method: 'POST', body: { email, password } });
    setToken(res.token);
    return res.user; // { id, name, email, roles, isAdmin, verified }
  },
  async logout() {
    try { await request('/api/auth/logout', { method: 'POST' }); } finally { setToken(null); }
  },
  me: () => request('/api/auth/me'),

  // Connexion Google / Facebook : le navigateur change de page (pas d'appel AJAX) ; au retour : /connexion/retour#token=…
  oauth: {
    providers: () => request('/api/auth/oauth/providers'),                       // { providers: [{ id: 'google', label: 'Google' }] } (seulement les fournisseurs configurés)
    startUrl: (provider, lang = 'fr') => `${BASE}/api/auth/oauth/${provider}/start?lang=${lang}`, // window.location.assign(startUrl)
    async complete(token) { setToken(token); return request('/api/auth/me'); }, // jeton lu dans le fragment de l'adresse -> profil
  },
  account: {
    export: () => request('/api/auth/me/export'),                                          // RGPD : copie de mes données
    remove: (password) => request('/api/auth/me', { method: 'DELETE', body: { password } }), // RGPD : supprimer mon compte
  },

  // --- Boutique (listes : { data, meta: { page, perPage, total, pageCount } })
  products: {
    list: (q = {}) => request('/api/products', { query: q }),      // { category, q, page, perPage, published (admin) }
    get: (id) => request(`/api/products/${id}`),
    create: (p) => request('/api/products', { method: 'POST', body: p }),
    update: (id, p) => request(`/api/products/${id}`, { method: 'PATCH', body: p }),
    remove: (id) => request(`/api/products/${id}`, { method: 'DELETE' }),
  },
  categories: {
    list: () => request('/api/categories'),
    create: (name) => request('/api/categories', { method: 'POST', body: { name } }),
    rename: (id, name) => request(`/api/categories/${id}`, { method: 'PATCH', body: { name } }),
    remove: (id) => request(`/api/categories/${id}`, { method: 'DELETE' }),
  },
  orders: {
    create: ({ customer, items }) => request('/api/orders', { method: 'POST', body: { customer, items: items.map((i) => ({ productId: i.id, qty: i.qty })) } }),
    list: (q = {}) => request('/api/orders', { query: q }),         // admin : { status, q, page, perPage }
    get: (ref) => request(`/api/orders/${ref}`),
    setStatus: (ref, status) => request(`/api/orders/${ref}/status`, { method: 'PATCH', body: { status } }),
    // Paiement Stripe : create() renvoie { …commande, checkoutUrl } -> window.location.assign(checkoutUrl) ; au retour de Stripe :
    checkoutStatus: (sessionId) => request(`/api/payments/checkout/${sessionId}`),   // { id, status, paid, refunded, total, customer: { email } }
    refund: (ref) => request(`/api/orders/${ref}/refund`, { method: 'POST' }),       // admin : remboursement total
  },

  // --- Contact (public) et messages reçus (admin)
  contact: {
    send: (f) => request('/api/contact', { method: 'POST', body: f }),   // { name, email, subject, message, consent, website:'' }
  },
  messages: {
    list: (q = {}) => request('/api/messages', { query: q }),            // { read: 0|1, q, page, perPage }
    setRead: (id, read) => request(`/api/messages/${id}`, { method: 'PATCH', body: { read } }),
    remove: (id) => request(`/api/messages/${id}`, { method: 'DELETE' }),
  },

  // --- Contenu du site et slider
  content: {
    get: () => request('/api/content'),
    save: (content) => request('/api/content', { method: 'PUT', body: content }),
    reset: () => request('/api/content/reset', { method: 'POST' }),
  },
  slider: {
    get: () => request('/api/slider'),                               // { settings, products }
    save: (settings) => request('/api/slider', { method: 'PUT', body: settings }),
  },

  // --- Photos et tableau de bord (admin)
  upload: (file) => { const form = new FormData(); form.append('file', file); return request('/api/uploads', { method: 'POST', form }); },
  stats: () => request('/api/admin/stats'),
};
