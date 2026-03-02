<?php

function handleContactRoutes($uri, $method)
{
    $tokenData = JWTHandler::requireAuth();
    $userId = $tokenData['userId'];

    if ($uri === '/contacts' && $method === 'GET') {
        getContacts($userId);
    } elseif ($uri === '/contacts' && $method === 'POST') {
        createContact($userId);
    } elseif (preg_match('/^\/contacts\/(\d+)$/', $uri, $matches) && $method === 'PUT') {
        updateContact($userId, (int)$matches[1]);
    } elseif (preg_match('/^\/contacts\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
        deleteContact($userId, (int)$matches[1]);
    } else {
        Response::error('Route not found', 404);
    }
}

function getContacts($userId)
{
    try {
        $db = getDB();
        $contacts = $db->fetchAll(
            "SELECT id, name, upi_id, notes, created_at FROM trusted_contacts WHERE user_id = ? ORDER BY name ASC",
            [$userId]
        );
        Response::success($contacts, 'Trusted contacts retrieved successfully');
    } catch (Exception $e) {
        Response::error('Failed to fetch contacts: ' . $e->getMessage(), 500);
    }
}

function createContact($userId)
{
    try {
        $input = getJsonInput();

        $errors = validateRequired($input, ['name']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $name   = trim($input['name']);
        $upiId  = isset($input['upi_id'])  ? trim($input['upi_id'])  : null;
        $notes  = isset($input['notes'])   ? trim($input['notes'])   : null;

        if (empty($name)) {
            Response::error('Name cannot be empty', 422);
        }

        $db = getDB();
        $id = $db->insert(
            "INSERT INTO trusted_contacts (user_id, name, upi_id, notes) VALUES (?, ?, ?, ?)",
            [$userId, $name, $upiId, $notes]
        );

        $contact = $db->fetchOne(
            "SELECT id, name, upi_id, notes, created_at FROM trusted_contacts WHERE id = ?",
            [$id]
        );

        Response::success($contact, 'Contact added successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to create contact: ' . $e->getMessage(), 500);
    }
}

function updateContact($userId, $contactId)
{
    try {
        $db = getDB();
        $existing = $db->fetchOne(
            "SELECT id FROM trusted_contacts WHERE id = ? AND user_id = ?",
            [$contactId, $userId]
        );

        if (!$existing) {
            Response::error('Contact not found', 404);
        }

        $input = getJsonInput();
        $fields = [];
        $params = [];

        if (isset($input['name']) && trim($input['name']) !== '') {
            $fields[] = 'name = ?';
            $params[] = trim($input['name']);
        }
        if (array_key_exists('upi_id', $input)) {
            $fields[] = 'upi_id = ?';
            $params[] = $input['upi_id'] !== null ? trim($input['upi_id']) : null;
        }
        if (array_key_exists('notes', $input)) {
            $fields[] = 'notes = ?';
            $params[] = $input['notes'] !== null ? trim($input['notes']) : null;
        }

        if (empty($fields)) {
            Response::error('No fields to update', 422);
        }

        $params[] = $contactId;
        $db->execute(
            "UPDATE trusted_contacts SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );

        $contact = $db->fetchOne(
            "SELECT id, name, upi_id, notes, created_at FROM trusted_contacts WHERE id = ?",
            [$contactId]
        );

        Response::success($contact, 'Contact updated successfully');
    } catch (Exception $e) {
        Response::error('Failed to update contact: ' . $e->getMessage(), 500);
    }
}

function deleteContact($userId, $contactId)
{
    try {
        $db = getDB();
        $existing = $db->fetchOne(
            "SELECT id FROM trusted_contacts WHERE id = ? AND user_id = ?",
            [$contactId, $userId]
        );

        if (!$existing) {
            Response::error('Contact not found', 404);
        }

        $db->execute(
            "DELETE FROM trusted_contacts WHERE id = ? AND user_id = ?",
            [$contactId, $userId]
        );

        Response::success(null, 'Contact deleted successfully');
    } catch (Exception $e) {
        Response::error('Failed to delete contact: ' . $e->getMessage(), 500);
    }
}
