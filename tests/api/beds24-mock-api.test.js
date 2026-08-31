import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';

const MOCK_PORT = 9999;
let server;

/**
 * Mock Beds24 API v2 local HTTP Server.
 *
 * Simulates authentication setup, token refreshes, and compressed daily rates calendar.
 */
test.before(() => {
	server = http.createServer((req, res) => {
		const parsedUrl = new URL(req.url || '', `http://localhost:${MOCK_PORT}`);
		const pathname = parsedUrl.pathname;
		const method = req.method;

		res.setHeader('Content-Type', 'application/json');

		// 1. Mock GET /authentication/setup (Invite Code Exchange)
		if (method === 'GET' && pathname === '/authentication/setup') {
			const inviteCode = req.headers['code'] || '';
			if (!inviteCode) {
				res.writeHead(400);
				res.end(JSON.stringify({ error: 'Missing invitation setup code' }));
				return;
			}

			res.writeHead(200);
			res.end(JSON.stringify({
				token: 'mock_access_token_abc123',
				expiresIn: 86400,
				refreshToken: 'mock_refresh_token_xyz789'
			}));
			return;
		}

		// 2. Mock GET /authentication/token (Token Refresh)
		if (method === 'GET' && pathname === '/authentication/token') {
			const refreshToken = req.headers['refreshtoken'] || '';
			if (!refreshToken) {
				res.writeHead(401);
				res.end(JSON.stringify({ error: 'Missing refreshToken header' }));
				return;
			}

			res.writeHead(200);
			res.end(JSON.stringify({
				token: 'mock_refreshed_access_token_456',
				expiresIn: 86400
			}));
			return;
		}

		// 3. Mock GET /inventory/rooms/calendar (Daily Pricing Calendar)
		if (method === 'GET' && pathname === '/inventory/rooms/calendar') {
			const accessToken = req.headers['token'] || '';
			if (!accessToken) {
				res.writeHead(401);
				res.end(JSON.stringify({ error: 'Unauthorized: Missing access token' }));
				return;
			}

			const roomId = parsedUrl.searchParams.get('roomId') || '';
			const from = parsedUrl.searchParams.get('from') || '';
			const to = parsedUrl.searchParams.get('to') || '';

			if (!roomId || !from || !to) {
				res.writeHead(400);
				res.end(JSON.stringify({ error: 'Missing roomId, from, or to query parameters' }));
				return;
			}

			res.writeHead(200);
			res.end(JSON.stringify({
				success: true,
				data: [
					{
						roomId: parseInt(roomId, 10),
						propertyId: 74130,
						name: 'Mock Apartment Room',
						calendar: [
							{
								from: from,
								to: from,
								price1: 100.00
							},
							{
								from: to,
								to: to,
								price1: 107.00
							}
						]
					}
				]
			}));
			return;
		}

		// 4. Mock GET /properties (Beds24 Properties and Rooms)
		if (method === 'GET' && pathname === '/properties') {
			const accessToken = req.headers['token'] || '';
			if (!accessToken) {
				res.writeHead(401);
				res.end(JSON.stringify({ error: 'Unauthorized: Missing access token' }));
				return;
			}

			const includeAllRooms = parsedUrl.searchParams.get('includeAllRooms') || '';
			if (includeAllRooms === 'true') {
				res.writeHead(200);
				res.end(JSON.stringify([
					{
						id: 74130,
						name: 'Gorgeous Studio in Midtown Manhattan',
						roomTypes: [
							{
								id: 170328,
								name: 'Apartment 4'
							}
						]
					}
				]));
			} else {
				res.writeHead(200);
				// To simulate the bug where rooms are not returned when using the incorrect/missing includeAllRooms parameter
				res.end(JSON.stringify([
					{
						id: 74130,
						name: 'Gorgeous Studio in Midtown Manhattan'
					}
				]));
			}
			return;
		}

		// Fallback for missing endpoints
		res.writeHead(404);
		res.end(JSON.stringify({ error: 'Endpoint not found' }));
	});

	server.listen(MOCK_PORT);
});

test.after(() => {
	server.close();
});

// =============================================================================
// NATIVE NODE.JS API TESTS
// =============================================================================

test('GET /authentication/setup - Valid exchange returns token layout', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/authentication/setup`, {
		headers: { 'code': 'valid_invite_code_123' }
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.equal(data.token, 'mock_access_token_abc123');
	assert.equal(data.expiresIn, 86400);
	assert.equal(data.refreshToken, 'mock_refresh_token_xyz789');
});

test('GET /authentication/setup - Missing header returns HTTP 400', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/authentication/setup`);
	assert.equal(res.status, 400);
	const data = await res.json();
	assert.ok(data.error.includes('Missing'));
});

test('GET /authentication/token - Valid refresh returns fresh credentials', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/authentication/token`, {
		headers: { 'refreshtoken': 'mock_refresh_token_xyz789' }
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.equal(data.token, 'mock_refreshed_access_token_456');
	assert.equal(data.expiresIn, 86400);
});

test('GET /authentication/token - Missing refresh token returns HTTP 401', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/authentication/token`);
	assert.equal(res.status, 401);
	const data = await res.json();
	assert.ok(data.error.includes('Missing'));
});

test('GET /inventory/rooms/calendar - Valid token and query returns pricing matrices', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/inventory/rooms/calendar?roomId=170328&from=2026-09-01&to=2026-09-02`, {
		headers: { 'token': 'mock_access_token_abc123' }
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.equal(data.success, true);
	assert.ok(Array.isArray(data.data));
	
	const roomData = data.data[0];
	assert.equal(roomData.roomId, 170328);
	assert.equal(roomData.calendar[0].price1, 100);
	assert.equal(roomData.calendar[1].price1, 107);
});

test('GET /inventory/rooms/calendar - Missing token returns HTTP 401 Unauthorized', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/inventory/rooms/calendar?roomId=170328&from=2026-09-01&to=2026-09-02`);
	assert.equal(res.status, 401);
	const data = await res.json();
	assert.ok(data.error.includes('Unauthorized'));
});

test('GET /properties - with includeAllRooms=true returns properties and rooms', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/properties?includeAllRooms=true`, {
		headers: { 'token': 'mock_access_token_abc123' }
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.ok(Array.isArray(data));
	assert.equal(data[0].id, 74130);
	assert.ok(Array.isArray(data[0].roomTypes));
	assert.equal(data[0].roomTypes[0].id, 170328);
	assert.equal(data[0].roomTypes[0].name, 'Apartment 4');
});

test('GET /properties - with incorrect or missing includeAllRooms parameter does not return rooms list', async () => {
	const res = await fetch(`http://localhost:${MOCK_PORT}/properties?includeRooms=true`, {
		headers: { 'token': 'mock_access_token_abc123' }
	});

	assert.equal(res.status, 200);
	const data = await res.json();
	assert.ok(Array.isArray(data));
	assert.equal(data[0].id, 74130);
	assert.equal(data[0].roomTypes, undefined);
});
