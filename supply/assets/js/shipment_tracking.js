// GPS Tracking for Shipments
let trackingMaps = {};
let trackingIntervals = {};

// Initialize GPS tracking map
function initGPSTrackingMap(shipmentId, currentLat, currentLng, destinationLat, destinationLng) {
    const mapContainer = document.getElementById(`gpsTrackingMap_${shipmentId}`);
    if (!mapContainer) return null;
    
    // Remove existing map if present
    if (trackingMaps[shipmentId]) {
        trackingMaps[shipmentId].map.remove();
    }
    
    // Initialize map
    const map = L.map(`gpsTrackingMap_${shipmentId}`).setView([currentLat, currentLng], 12);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    // Add destination marker
    if (destinationLat && destinationLng) {
        L.marker([destinationLat, destinationLng], {
            icon: L.icon({
                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                iconSize: [25, 41],
                iconAnchor: [12, 41]
            })
        }).addTo(map).bindPopup('Destination').openPopup();
    }
    
    // Add current location marker (will be updated)
    const currentMarker = L.marker([currentLat, currentLng], {
        icon: L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                iconSize: [25, 41],
                iconAnchor: [12, 41]
        })
    }).addTo(map).bindPopup('Current Location');
    
    // Add route line (will be updated)
    let routeLine = null;
    
    trackingMaps[shipmentId] = {
        map: map,
        currentMarker: currentMarker,
        routeLine: routeLine,
        destinationLat: destinationLat,
        destinationLng: destinationLng
    };
    
    return map;
}

// Update GPS location on map
function updateGPSLocation(shipmentId, lat, lng, speed, heading) {
    if (!trackingMaps[shipmentId]) return;
    
    const tracking = trackingMaps[shipmentId];
    
    // Update marker position
    tracking.currentMarker.setLatLng([lat, lng]);
    tracking.currentMarker.bindPopup(`Current Location<br>Speed: ${speed || 'N/A'} km/h`).openPopup();
    
    // Update map view
    tracking.map.setView([lat, lng], 13);
    
    // Draw route to destination if available
    if (tracking.destinationLat && tracking.destinationLng) {
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${lng},${lat};${tracking.destinationLng},${tracking.destinationLat}?overview=full&geometries=geojson`;
        
        fetch(osrmUrl)
            .then(response => response.json())
            .then(data => {
                if (data.code === 'Ok' && data.routes && data.routes.length > 0) {
                    const route = data.routes[0];
                    const geometry = route.geometry;
                    const latlngs = geometry.coordinates.map(coord => [coord[1], coord[0]]);
                    
                    // Remove old route
                    if (tracking.routeLine) {
                        tracking.map.removeLayer(tracking.routeLine);
                    }
                    
                    // Draw new route
                    tracking.routeLine = L.polyline(latlngs, {
                        color: '#ff6b6b',
                        weight: 4,
                        opacity: 0.7
                    }).addTo(tracking.map);
                    
                    // Fit bounds to show both current location and destination
                    tracking.map.fitBounds([
                        [lat, lng],
                        [tracking.destinationLat, tracking.destinationLng]
                    ], { padding: [50, 50] });
                }
            })
            .catch(error => console.error('Error fetching route:', error));
    }
}

// Fetch latest GPS location
function fetchGPSLocation(shipmentId) {
    fetch(`?ajax=get_shipment_gps&shipment_id=${shipmentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Error:', data.error);
                return;
            }
            
            if (data.current_latitude && data.current_longitude) {
                // Update map
                updateGPSLocation(
                    shipmentId,
                    data.current_latitude,
                    data.current_longitude,
                    data.current_speed,
                    data.current_heading
                );
                
                // Update info cards
                const locationEl = document.getElementById(`currentLocation_${shipmentId}`);
                const speedEl = document.getElementById(`currentSpeed_${shipmentId}`);
                const etaEl = document.getElementById(`updatedETA_${shipmentId}`);
                
                if (locationEl) {
                    locationEl.textContent = 
                        `${data.current_latitude.toFixed(6)}, ${data.current_longitude.toFixed(6)}`;
                }
                
                if (speedEl && data.current_speed) {
                    speedEl.textContent = `${data.current_speed.toFixed(1)} km/h`;
                }
                
                if (etaEl && data.estimated_arrival) {
                    const eta = new Date(data.estimated_arrival);
                    etaEl.textContent = eta.toLocaleTimeString();
                }
            }
        })
        .catch(error => console.error('Error fetching GPS location:', error));
}

// Start real-time tracking
function startGPSTracking(shipmentId, initialLat, initialLng, destLat, destLng) {
    // Initialize map
    initGPSTrackingMap(shipmentId, initialLat, initialLng, destLat, destLng);
    
    // Fetch location immediately
    fetchGPSLocation(shipmentId);
    
    // Set up interval to update every 30 seconds
    if (trackingIntervals[shipmentId]) {
        clearInterval(trackingIntervals[shipmentId]);
    }
    
    trackingIntervals[shipmentId] = setInterval(() => {
        fetchGPSLocation(shipmentId);
    }, 30000); // Update every 30 seconds
}

// Stop tracking
function stopGPSTracking(shipmentId) {
    if (trackingIntervals[shipmentId]) {
        clearInterval(trackingIntervals[shipmentId]);
        delete trackingIntervals[shipmentId];
    }
    
    if (trackingMaps[shipmentId]) {
        trackingMaps[shipmentId].map.remove();
        delete trackingMaps[shipmentId];
    }
}

// Load location history
function loadLocationHistory(shipmentId) {
    fetch(`?ajax=get_location_history&shipment_id=${shipmentId}`)
        .then(response => response.json())
        .then(data => {
            const historyContainer = document.getElementById(`historyTimeline_${shipmentId}`);
            if (!historyContainer) return;
            
            if (data.error) {
                historyContainer.innerHTML = '<p class="text-muted">No location updates yet. GPS tracking will appear here once location data is received.</p>';
                return;
            }
            
            if (data.history && data.history.length > 0) {
                let html = '<div class="timeline">';
                // Display in chronological order (oldest first)
                data.history.forEach(location => {
                    const time = new Date(location.recorded_at);
                    let locationName = 'Unknown Location';
                    if (location.location_name) {
                        locationName = location.location_name;
                    } else if (location.latitude != null && location.longitude != null) {
                        locationName = `${parseFloat(location.latitude).toFixed(6)}, ${parseFloat(location.longitude).toFixed(6)}`;
                    }
                    html += `
                        <div class="timeline-item" style="padding: 0.5rem; border-left: 2px solid #ddd; margin-bottom: 0.5rem; padding-left: 1rem;">
                            <strong>${locationName}</strong><br>
                            <small>${time.toLocaleString()}</small>
                            ${location.speed_kmh ? `<br><small>Speed: ${parseFloat(location.speed_kmh).toFixed(1)} km/h</small>` : ''}
                        </div>
                    `;
                });
                html += '</div>';
                historyContainer.innerHTML = html;
            } else {
                historyContainer.innerHTML = '<p class="text-muted">No location updates yet. GPS tracking will appear here once location data is received.</p>';
            }
        })
        .catch(error => {
            console.error('Error loading history:', error);
            const historyContainer = document.getElementById(`historyTimeline_${shipmentId}`);
            if (historyContainer) {
                historyContainer.innerHTML = '<p class="text-muted">No location updates yet. GPS tracking will appear here once location data is received.</p>';
            }
        });
}

