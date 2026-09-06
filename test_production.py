import requests
import sys

session = requests.Session()
BASE_URL = 'https://www.ecolevaeco.com/api'

resp = session.get(f'{BASE_URL}/auth/me.php')
session.headers.update({
    'X-CSRF-Token': resp.json().get('csrf_token'),
    'Content-Type': 'application/json',
    'Accept': 'application/json'
})

resp = session.post(f'{BASE_URL}/auth/login.php', json={'username': 'admin', 'password': 'Admin123!'})
new_token = resp.json().get('csrf_token')
if new_token: session.headers.update({'X-CSRF-Token': new_token})

resp_c = session.get(f"{BASE_URL}/clients/index.php")
clients = [c for c in resp_c.json().get('clients', []) if c['email'] in ['s.ierra.csi@gmail.com', 'si.erra.csi@gmail.com', 'sier.ra.csi@gmail.com']]

print("Updating clients with whatsapp number...")
for c in clients:
    update_data = {
        'client_id': c['id'],
        'name': c['name'],
        'email': c['email'],
        'whatsapp': '5521999193898',
        'document': c['document'],
        'monthly_value': float(c['monthly_value']),
        'due_day': int(c['due_day']),
        'status': c['status']
    }
    r = session.post(f"{BASE_URL}/clients/edit.php", json=update_data)
    print(f"Update client {c['id']} status: {r.status_code} {r.text}")

print("\nRe-sending invoices via Whatsapp...")
for inv_id in [1, 2, 3]:
    r_send = session.post(f"{BASE_URL}/invoices/index.php", json={'action': 'send', 'id': inv_id})
    print(f"Resend invoice {inv_id} status: {r_send.status_code} {r_send.text}")
