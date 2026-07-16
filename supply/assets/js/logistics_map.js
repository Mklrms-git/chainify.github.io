// Logistics Map - Route Planning
let map = null;
let routeLayer = null;
let originMarker = null;
let destinationMarker = null;

// Map instances for different modals
window.mapInstances = {};

// Initialize map
function initRouteMap(containerId, defaultLat = 14.5995, defaultLng = 120.9842) {
    // Remove existing map if it exists
    if (window.mapInstances && window.mapInstances[containerId]) {
        window.mapInstances[containerId].remove();
    }
    
    // Create new map instance
    const newMap = L.map(containerId).setView([defaultLat, defaultLng], 10);
    
    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(newMap);
    
    // Store map instance
    if (!window.mapInstances) window.mapInstances = {};
    window.mapInstances[containerId] = newMap;
    map = newMap; // Set as current map
    
    // Reset markers and route for this map
    routeLayer = null;
    originMarker = null;
    destinationMarker = null;
    
    return newMap;
}

// Add marker to map
function addMarker(lat, lng, title, isOrigin = true) {
    // Use default Leaflet markers with custom colors
    const iconColor = isOrigin ? 'red' : 'green';
    const icon = L.icon({
        iconUrl: `https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-${iconColor === 'red' ? '2x-red' : '2x-green'}.png`,
        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41]
    });
    
    const marker = L.marker([lat, lng], { icon: icon }).addTo(map);
    marker.bindPopup(title).openPopup();
    
    if (isOrigin) {
        if (originMarker) map.removeLayer(originMarker);
        originMarker = marker;
    } else {
        if (destinationMarker) map.removeLayer(destinationMarker);
        destinationMarker = marker;
    }
    
    map.setView([lat, lng], 12);
    return marker;
}

// Draw route between two points using OSRM (actual driving route)
function drawRoute(originLat, originLng, destLat, destLng) {
    // Remove existing route
    if (routeLayer) {
        map.removeLayer(routeLayer);
    }
    
    // Use OSRM API to get actual driving route (FREE, no API key needed)
    const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${originLng},${originLat};${destLng},${destLat}?overview=full&geometries=geojson`;
    
    // Return promise for async route fetching
    return fetch(osrmUrl)
        .then(response => response.json())
        .then(data => {
            if (data.code === 'Ok' && data.routes && data.routes.length > 0) {
                const route = data.routes[0];
                const geometry = route.geometry;
                
                // Convert GeoJSON coordinates to LatLng array
                const latlngs = geometry.coordinates.map(coord => [coord[1], coord[0]]);
                
                // Draw the actual route on map
                const routePolyline = L.polyline(latlngs, {
                    color: '#3388ff',
                    weight: 5,
                    opacity: 0.8,
                    smoothFactor: 1
                }).addTo(map);
                
                routeLayer = routePolyline;
                
                // Fit map to show the entire route
                map.fitBounds(routePolyline.getBounds(), { padding: [50, 50] });
                
                // Return actual driving distance and duration from OSRM
                const distanceKm = route.distance / 1000; // Convert meters to km
                const durationSeconds = route.duration; // Duration in seconds
                const durationMinutes = Math.round(durationSeconds / 60); // Convert to minutes
                
                return {
                    distance: distanceKm,
                    duration: durationMinutes
                };
            } else {
                // Fallback to straight line if OSRM fails
                console.warn('OSRM routing failed, using straight line');
                const route = L.polyline(
                    [[originLat, originLng], [destLat, destLng]],
                    { color: 'blue', weight: 4, opacity: 0.7, dashArray: '10, 10' }
                ).addTo(map);
                
                routeLayer = route;
                map.fitBounds([[originLat, originLng], [destLat, destLng]], { padding: [50, 50] });
                
                // Fallback: calculate distance and estimate time
                const distanceKm = calculateDistance(originLat, originLng, destLat, destLng);
                const durationMinutes = calculateETA(distanceKm);
                
                return {
                    distance: distanceKm,
                    duration: durationMinutes
                };
            }
        })
        .catch(error => {
            console.error('Error fetching route from OSRM:', error);
            // Fallback to straight line on error
            const route = L.polyline(
                [[originLat, originLng], [destLat, destLng]],
                { color: 'orange', weight: 4, opacity: 0.7, dashArray: '10, 10' }
            ).addTo(map);
            
            routeLayer = route;
            map.fitBounds([[originLat, originLng], [destLat, destLng]], { padding: [50, 50] });
            
            // Fallback: calculate distance and estimate time
            const distanceKm = calculateDistance(originLat, originLng, destLat, destLng);
            const durationMinutes = calculateETA(distanceKm);
            
            return {
                distance: distanceKm,
                duration: durationMinutes
            };
        });
}

// Calculate distance between two points (Haversine formula)
function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 6371; // Earth's radius in km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLng/2) * Math.sin(dLng/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    const distance = R * c; // Distance in km
    return distance;
}

// Calculate estimated time (assuming average speed of 50 km/h)
function calculateETA(distanceKm) {
    const avgSpeed = 50; // km/h
    const timeHours = distanceKm / avgSpeed;
    const timeMinutes = Math.round(timeHours * 60);
    return timeMinutes;
}

// Clear map
function clearRoute() {
    if (routeLayer) {
        map.removeLayer(routeLayer);
        routeLayer = null;
    }
    if (originMarker) {
        map.removeLayer(originMarker);
        originMarker = null;
    }
    if (destinationMarker) {
        map.removeLayer(destinationMarker);
        destinationMarker = null;
    }
}

