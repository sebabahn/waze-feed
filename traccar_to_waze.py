import asyncio
import json
import xml.etree.ElementTree as ET
import aiohttp
import websockets
from datetime import datetime
import os
from geopy.geocoders import Nominatim
from aiohttp import web

# ================== KONFIGURATION ÜBER ENVIRONMENT VARIABLES ==================
TRACCAR_WS_URL = os.getenv("TRACCAR_WS_URL", "wss://your-traccar-server.de/api/socket")
USERNAME = os.getenv("TRACCAR_USERNAME")
PASSWORD = os.getenv("TRACCAR_PASSWORD")

OUTPUT_FILE = "/app/waze_feed.xml"
PORT = int(os.getenv("PORT", 8080))

FILTER_KEYWORDS = [kw.strip().upper() for kw in os.getenv("FILTER_KEYWORDS", "RTW,NEF,LF,HLF,POL,FEUER,RW,ELW").split(",")]

GEOCODER_USER_AGENT = os.getenv("GEOCODER_USER_AGENT", "Waze-Einsatzfeed/1.0")

# =============================================================================

geolocator = Nominatim(user_agent=GEOCODER_USER_AGENT)

async def get_street_name(lat: float, lon: float) -> str:
    try:
        loop = asyncio.get_event_loop()
        location = await loop.run_in_executor(
            None, 
            lambda: geolocator.reverse((lat, lon), exactly_one=True, language="de", timeout=6)
        )
        if location:
            address = location.raw.get('address', {})
            return address.get('road') or address.get('street') or str(location.address).split(',')[0]
    except Exception as e:
        print(f"Geocoding Fehler: {e}")
    return "Einsatzfahrt"

def should_send_alert(position: dict) -> bool:
    attrs = position.get('attributes', {})
    device_name = position.get('deviceName', '').upper()
    if any(kw in device_name for kw in FILTER_KEYWORDS):
        return True
    if attrs.get('sondersignal') or attrs.get('emergency') or attrs.get('blue_light'):
        return True
    return False

def create_cifs_xml(positions):
    root = ET.Element("incidents")
    for pos in positions:
        if not should_send_alert(pos):
            continue
        lat = pos.get('latitude')
        lon = pos.get('longitude')
        if not lat or not lon:
            continue
        device_name = pos.get('deviceName', 'Einsatzfahrzeug')
        speed = pos.get('speed', 0)
        
        incident_id = f"{device_name.replace(' ', '-')}-{datetime.now().strftime('%Y%m%d-%H%M%S')}"
        
        incident = ET.SubElement(root, "incident")
        ET.SubElement(incident, "id").text = incident_id
        ET.SubElement(incident, "type").text = "HAZARD"
        ET.SubElement(incident, "subtype").text = "HAZARD_ON_ROAD_EMERGENCY_VEHICLE"
        
        polyline = f"{lat:.6f} {lon:.6f}"
        if speed > 8:
            offset = 0.00045
            polyline += f" {lat + offset:.6f} {lon + offset:.6f}"
        
        ET.SubElement(incident, "polyline").text = polyline
        ET.SubElement(incident, "direction").text = "ONE_DIRECTION"
        
        street = asyncio.run(get_street_name(lat, lon))
        ET.SubElement(incident, "street").text = street
        
        description = f"Einsatzfahrzeug unterwegs ({device_name})"
        if pos.get('attributes', {}).get('sondersignal'):
            description += " - Sondersignal"
        ET.SubElement(incident, "description").text = description
        
        fix_time = pos.get('fixTime') or pos.get('deviceTime')
        if fix_time:
            start_time = fix_time.replace("Z", "+00:00") if isinstance(fix_time, str) and "Z" in fix_time else fix_time
            ET.SubElement(incident, "starttime").text = start_time
        
        location = ET.SubElement(incident, "location")
        ET.SubElement(location, "latitude").text = str(lat)
        ET.SubElement(location, "longitude").text = str(lon)
    
    xml_str = ET.tostring(root, encoding="unicode", method="xml")
    return f'<?xml version="1.0" encoding="UTF-8"?>\n{xml_str}'

async def start_http_server():
    app = web.Application()
    async def handle_feed(request):
        if os.path.exists(OUTPUT_FILE):
            return web.FileResponse(OUTPUT_FILE, headers={'Content-Type': 'application/xml'})
        return web.Response(text="<incidents/>", content_type="application/xml")
    
    app.router.add_get('/', handle_feed)
    app.router.add_get('/feed.xml', handle_feed)
    
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, '0.0.0.0', PORT)
    await site.start()
    print(f"🌐 HTTP Server läuft auf Port {PORT} → http://localhost:{PORT}/feed.xml")

async def main():
    if not USERNAME or not PASSWORD:
        print("❌ TRACCAR_USERNAME und TRACCAR_PASSWORD müssen gesetzt sein!")
        return
    
    print("🚨 Traccar WebSocket → Waze CIFS Docker Container gestartet")
    await start_http_server()
    
    # Login mit moderner Methode (keine Deprecation)
    async with aiohttp.ClientSession() as session:
        try:
            auth_header = aiohttp.BasicAuth(USERNAME, PASSWORD).encode()
            await session.post(
                TRACCAR_WS_URL.replace('/api/socket', '/api/session'),
                headers={"Authorization": auth_header}
            )
            print("✅ Traccar Login erfolgreich")
        except Exception as e:
            print(f"Login Warnung (kann ignoriert werden): {e}")
    
    while True:
        try:
            async with websockets.connect(
                TRACCAR_WS_URL, 
                ping_interval=20, 
                ping_timeout=30,
                close_timeout=5
            ) as ws:
                print("✅ WebSocket verbunden")
                async for message in ws:
                    data = json.loads(message)
                    positions = data.get('positions', [])
                    if positions:
                        xml_content = create_cifs_xml(positions)
                        with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
                            f.write(xml_content)
                        active = len([p for p in positions if should_send_alert(p)])
                        print(f"✅ Feed aktualisiert ({active} aktive Fahrzeuge)")
        except Exception as e:
            print(f"WebSocket Fehler: {e} → Neustart in 5s...")
            await asyncio.sleep(5)

if __name__ == "__main__":
    asyncio.run(main())