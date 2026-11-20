const user = JSON.parse(localStorage.getItem('mannan_user'));
if (!user) {
    window.location.href = 'index.html';
}

let menuItems = [];
let tablesList = [];
let ordersList = [];
let cart = [];
let clientToken = newToken();   // unique per ticket: stops duplicate order submissions
let submitting = false;

function newToken() {
    return (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
        : Date.now() + '-' + Math.random().toString(36).slice(2);
}

// Sends a request; if the login session has expired, go back to the login page.
async function api(url, options = {}) {
    const res = await fetch(url, options);
    if (res.status === 401) {
        localStorage.removeItem('mannan_user');
        window.location.href = 'index.html';
        throw new Error('Session expired');
    }
    return res.json();
}

function postJSON(url, body) {
    return api(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
}

// Prevents user-typed text (e.g. a cancel reason) from being run as HTML.
function esc(text) {
    return String(text ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Initialize App & Apply Role-Based Access
document.addEventListener('DOMContentLoaded', () => {
    if (!user) return;   // being sent to the login page
    document.getElementById('userNameDisplay').textContent = `${user.name} (${user.email})`;
    document.getElementById('userRoleBadge').textContent = user.role;

    applyRolePermissions();
    loadAllData();
    setInterval(loadOrders, 10000);   // new kitchen tickets appear automatically
});

function applyRolePermissions() {
    const navButtons = document.querySelectorAll('.nav-btn');
    if (user.role === 'Kitchen') {
        navButtons.forEach(btn => {
            if (btn.dataset.tab !== 'kitchen' && btn.dataset.tab !== 'profile') btn.style.display = 'none';
        });
        switchTab('kitchen');
    } else if (user.role === 'Cashier' || user.role === 'Waiter') {
        navButtons.forEach(btn => {
            if (btn.dataset.tab === 'dashboard') btn.style.display = 'none';
        });
        switchTab('pos');
    }
}

function switchTab(tabName) {
    document.querySelectorAll('.tab-section').forEach(sec => sec.classList.remove('active'));
    document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById(`tab-${tabName}`).classList.add('active');
    document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
    loadAllData();
    if (tabName === 'profile') loadProfile();
}

async function logout() {
    try { await fetch('api/logout.php'); } catch (e) {}   // ends the server session
    localStorage.removeItem('mannan_user');
    window.location.href = 'index.html';
}

async function loadAllData() {
    const jobs = [loadMenu(), loadTables(), loadOrders()];
    // Revenue figures are only for Admin and Manager (the server enforces this too).
    if (user.role === 'Admin' || user.role === 'Manager') jobs.push(loadDashboard());
    await Promise.all(jobs);
}

// 1. Dashboard Data
let dashRange = 'today';
const rangeLabel = { today: 'Today', week: 'Last 7 days', month: 'Last 30 days' };
const money = n => `PKR ${Number(n || 0).toLocaleString()}`;
const emptyMsg = text => `<p class="empty-msg">${text}</p>`;

function setRange(range) {
    dashRange = range;
    document.querySelectorAll('.range-btn').forEach(b => b.classList.toggle('active', b.dataset.range === range));
    loadDashboard();
}

async function loadDashboard() {
    const d = await api(`api/dashboard.php?range=${dashRange}`);
    if (d.success === false) return;   // no permission

    const q = d.queue || {};
    const tables = d.tableSummary || [];
    const tableCount = name => Number((tables.find(t => t.status === name) || {}).count || 0);
    const tablesTotal = tables.reduce((sum, t) => sum + Number(t.count), 0);
    const set = (id, text) => { document.getElementById(id).textContent = text; };

    set('statSales', money(d.totalSales));
    set('statSalesSub', `${d.paidOrders} paid orders · ${rangeLabel[dashRange]}`);
    set('statTotalOrders', d.totalOrders);
    set('statTotalOrdersSub', rangeLabel[dashRange]);
    set('statActiveOrders', d.activeOrders);
    set('statQueueSub', `Waiting ${q.Confirmed || 0} · Preparing ${q.Preparing || 0} · Ready ${q.Ready || 0}`);
    set('statTablesBusy', `${tableCount('Occupied')} / ${tablesTotal}`);
    set('statTablesSub', `${tableCount('Available')} available · ${tableCount('Reserved')} reserved`);

    // Orders by type (dine-in / takeaway / delivery)
    const types = ['Dine-In', 'Takeaway', 'Delivery'];
    const counts = Object.fromEntries((d.byType || []).map(t => [t.order_type, Number(t.count)]));
    const maxCount = Math.max(1, ...types.map(t => counts[t] || 0));
    document.getElementById('panelTypes').innerHTML = types.map(t => `
        <div class="bar-row"><span>${t}</span>
            <div class="bar"><div class="bar-fill" style="width:${((counts[t] || 0) / maxCount) * 100}%"></div></div>
            <strong>${counts[t] || 0}</strong></div>`).join('');

    // Table status legend
    document.getElementById('panelTables').innerHTML = tables.length
        ? tables.map(t => `<div class="legend-row"><span class="dot s-${t.status.replace(/\s/g, '')}"></span>${t.status}<strong>${t.count}</strong></div>`).join('')
        : emptyMsg('No tables yet.');

    // Lists
    const fill = (id, items, render, empty) => {
        document.getElementById(id).innerHTML = items.length ? items.map(render).join('') : `<li class="empty-msg">${empty}</li>`;
    };
    fill('panelTop', d.topItems || [], i => `<li><span>${esc(i.name)}</span><span>${i.qty} sold · ${money(i.revenue)}</span></li>`, 'No sales in this period.');
    fill('panelRecent', d.recent || [], r => `<li><span><strong>${esc(r.order_number)}</strong> · ${esc(r.order_type)} · ${esc(r.payment_method)}</span><span>${money(r.total_amount)} · ${esc(String(r.created_at).slice(11, 16))}</span></li>`, 'No paid transactions yet.');
    fill('panelStaff', d.staff || [], s => `<li><span>${esc(s.name)}</span><span>${s.orders} orders · ${money(s.total)}</span></li>`, 'No staff sales in this period.');
}

// 2. Menu & POS Cart
async function loadMenu() {
    menuItems = await api('api/menu.php');
    const grid = document.getElementById('menuGrid');
    grid.innerHTML = menuItems.map(item => `
        <div class="menu-card" onclick="addToCart(${item.id})">
            <div class="cat">${item.category}</div>
            <h4>${item.name}</h4>
            <div class="price">PKR ${Number(item.price).toFixed(0)}</div>
        </div>
    `).join('');
}

function addToCart(id) {
    const item = menuItems.find(m => Number(m.id) === Number(id));
    const existing = cart.find(c => Number(c.id) === Number(id));
    if (existing) {
        existing.quantity += 1;
    } else {
        cart.push({ id: item.id, name: item.name, price: Number(item.price), quantity: 1, notes: '' });
    }
    renderCart();
}

function updateQty(index, delta) {
    cart[index].quantity += delta;
    if (cart[index].quantity <= 0) cart.splice(index, 1);
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    if (cart.length === 0) {
        container.innerHTML = '<p class="empty-msg">No items added yet.</p>';
    } else {
        container.innerHTML = cart.map((item, idx) => `
            <div class="cart-item">
                <div>
                    <strong>${item.name}</strong><br>
                    <small>PKR ${item.price} x ${item.quantity}</small>
                </div>
                <div>
                    <button class="btn-small" onclick="updateQty(${idx}, -1)">-</button>
                    <button class="btn-small" onclick="updateQty(${idx}, 1)">+</button>
                </div>
            </div>
        `).join('');
    }

    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discount = Number(document.getElementById('cartDiscount').value) || 0;
    const total = Math.max(0, subtotal - discount);

    document.getElementById('cartSubtotal').textContent = `PKR ${subtotal}`;
    document.getElementById('cartTotal').textContent = `PKR ${total}`;
}
function toggleTableSelect() {
    const type = document.getElementById('orderType').value;
    document.getElementById('tableSelectGroup').style.display = type === 'Dine-In' ? 'block' : 'none';
}

async function submitOrder() {
    if (cart.length === 0) return alert('Please add at least one item to the order.');
    const order_type = document.getElementById('orderType').value;
    const table_id = order_type === 'Dine-In' ? document.getElementById('posTableSelect').value : null;

    if (order_type === 'Dine-In' && !table_id) {
        return alert('Please select an available table for Dine-In.');
    }

    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discount = Number(document.getElementById('cartDiscount').value) || 0;
    const total_amount = Math.max(0, subtotal - discount);

    if (submitting) return;   // ignore double clicks
    submitting = true;
    try {
        // Prices and totals are recalculated by the server; we only send item ids and quantities.
        const data = await postJSON('api/orders.php', {
            action: 'create', client_token: clientToken, order_type, table_id, items: cart, discount
        });
        if (data.success) {
            alert(`Order ${data.order_number} confirmed and sent to Kitchen!`);
            cart = [];
            clientToken = newToken();
            document.getElementById('cartDiscount').value = 0;
            renderCart();
            loadAllData();
        } else {
            alert(data.message || 'Could not create the order.');
        }
    } catch (err) {
        alert('Server error. Please try again.');
    } finally {
        submitting = false;
    }
}

// 3. Tables Management
async function loadTables() {
    tablesList = await api('api/tables.php');

    const select = document.getElementById('posTableSelect');
    const available = tablesList.filter(t => t.status === 'Available');
    select.innerHTML = available.length
        ? available.map(t => `<option value="${t.id}">${t.table_number} (${t.capacity} Seats)</option>`).join('')
        : '<option value="">No available tables</option>';

    const grid = document.getElementById('tablesGrid');
    grid.innerHTML = tablesList.map(t => `
        <div class="table-card ${t.status}">
            <h4>${t.table_number}</h4>
            <p>Capacity: ${t.capacity} Persons</p>
            <span class="status-pill">${t.status}</span>
            <div style="margin-top:0.5rem;">
                <select onchange="changeTableStatus(${t.id}, this.value)" style="padding:4px; width:100%;">
                    <option value="">Change Status...</option>
                    <option value="Available">Available</option>
                    <option value="Occupied">Occupied</option>
                    <option value="Reserved">Reserved</option>
                    <option value="Needs Cleaning">Needs Cleaning</option>
                </select>
            </div>
        </div>
    `).join('');
}

async function changeTableStatus(id, status) {
    if (!status) return;
    const data = await postJSON('api/tables.php', { id, status });
    if (!data.success) alert(data.message);
    loadAllData();
}

// 4. Kitchen KDS & Orders Checkout
async function loadOrders() {
    ordersList = await api('api/orders.php');

    const activeOrders = ordersList.filter(o => ['Confirmed', 'Preparing', 'Ready'].includes(o.status));
    const kdsGrid = document.getElementById('kdsGrid');
    kdsGrid.innerHTML = activeOrders.length ? activeOrders.map(o => `
        <div class="kds-card ${o.status}">
            <div style="display:flex; justify-content:space-between;">
                <strong>${o.order_number}</strong>
                <span>${o.order_type} ${o.table_number ? '(' + o.table_number + ')' : ''}</span>
            </div>
            <p style="font-size:0.8rem; color:#64748b; margin-top:4px;">Status: <strong>${o.status}</strong></p>
            <ul class="kds-items">
                ${o.items.map(i => `<li>${i.quantity}x ${i.name}</li>`).join('')}
            </ul>
            <div style="display:flex; gap:0.5rem;">
                ${o.status === 'Confirmed' ? `<button class="btn btn-warning btn-block" onclick="updateOrderStatus(${o.id}, 'Preparing')">Start Preparing</button>` : ''}
                ${o.status === 'Preparing' ? `<button class="btn btn-primary btn-block" onclick="updateOrderStatus(${o.id}, 'Ready')">Mark Ready</button>` : ''}
                ${o.status === 'Ready' ? `<button class="btn btn-secondary btn-block" onclick="updateOrderStatus(${o.id}, 'Served')">Mark Served</button>` : ''}
            </div>
        </div>
    `).join('') : '<p>No active kitchen tickets right now.</p>';

    const tbody = document.getElementById('ordersTableBody');
    tbody.innerHTML = ordersList.map(o => `
        <tr>
            <td><strong>${o.order_number}</strong></td>
            <td>${o.order_type} ${o.table_number ? '- ' + o.table_number : ''}</td>
            <td>${o.items.map(i => `${i.quantity}x ${i.name}`).join(', ')}</td>
            <td>PKR ${Number(o.total_amount).toFixed(0)}</td>
            <td>${o.status}</td>
            <td>${o.payment_status} (${o.payment_method})</td>
            <td>${orderActions(o)}</td>
        </tr>
    `).join('');
}

async function updateOrderStatus(id, status) {
    const data = await postJSON('api/orders.php', { action: 'status', id, status });
    if (!data.success) alert(data.message);
    loadAllData();
}

async function payOrder(id) {
    const method = prompt('Enter Payment Method (Cash / Card / EasyPaisa / JazzCash):', 'Cash');
    if (!method) return;
    const data = await postJSON('api/orders.php', { action: 'pay', id, payment_method: method });
    if (!data.success) return alert(data.message);
    await loadAllData();
    viewReceipt(id);
}

async function cancelOrder(id) {
    const reason = prompt('Reason for cancelling this order:');
    if (!reason) return;
    const data = await postJSON('api/orders.php', { action: 'cancel', id, reason });
    if (!data.success) alert(data.message);
    loadAllData();
}

// Buttons shown in the Actions column, depending on order state and the user's role.
function orderActions(o) {
    if (o.status === 'Cancelled') return `<small>Cancelled: ${esc(o.cancel_reason)}</small>`;
    const canManage = user.role === 'Admin' || user.role === 'Manager';
    const canPay = canManage || user.role === 'Cashier';
    let html = '';
    if (o.payment_status === 'Unpaid') {
        if (canPay) html += `<button class="btn btn-primary" onclick="payOrder(${o.id})">Checkout & Pay</button> `;
        if (canManage) html += `<button class="btn-small" onclick="cancelOrder(${o.id})">Cancel</button>`;
    } else {
        html += `<button class="btn-small" onclick="viewReceipt(${o.id})">View Receipt</button>`;
    }
    return html;
}

function viewReceipt(id) {
    const order = ordersList.find(o => Number(o.id) === Number(id));
    if (!order) return;
    document.getElementById('receiptDetails').innerHTML = `
        <p>Order #: ${order.order_number}</p>
        <p>Type: ${order.order_type} ${order.table_number || ''}</p>
        <p>Date: ${order.created_at}</p>
        <hr>
        ${order.items.map(i => `<p>${i.quantity}x ${i.name} - PKR ${i.price * i.quantity}</p>`).join('')}
        <hr>
        <p>Subtotal: PKR ${order.subtotal}</p>
        <p>Discount: PKR ${order.discount}</p>
        <p><strong>Total Paid (${order.payment_method}): PKR ${order.total_amount}</strong></p>
    `;
    document.getElementById('receiptModal').classList.remove('hidden');
}

function closeReceipt() {
    document.getElementById('receiptModal').classList.add('hidden');
}

// 6. User profile
async function loadProfile() {
    const p = await api('api/profile.php');
    if (p.success === false) return;
    document.getElementById('profName').value = p.name;
    document.getElementById('profEmail').value = p.email;
    document.getElementById('profRole').value = p.role;
}

async function saveProfile() {
    const name = document.getElementById('profName').value.trim();
    const data = await postJSON('api/profile.php', { action: 'update_name', name });
    if (!data.success) return alert(data.message);
    user.name = name;
    localStorage.setItem('mannan_user', JSON.stringify(user));
    document.getElementById('userNameDisplay').textContent = `${user.name} (${user.email})`;
    alert('Profile updated.');
}

async function changePassword() {
    const current_password = document.getElementById('pwCurrent').value;
    const new_password = document.getElementById('pwNew').value;
    if (new_password !== document.getElementById('pwConfirm').value) return alert('New passwords do not match.');
    const data = await postJSON('api/profile.php', { action: 'change_password', current_password, new_password });
    if (!data.success) return alert(data.message);
    ['pwCurrent', 'pwNew', 'pwConfirm'].forEach(id => { document.getElementById(id).value = ''; });
    alert('Password updated.');
}