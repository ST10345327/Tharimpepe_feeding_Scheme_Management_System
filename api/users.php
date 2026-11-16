<?php

function transitionPendingRegistration(PDO $db, $userId, $decision, $actorUserId) {
    $transitions = [
        'approve' => ['user' => 'active', 'volunteer' => 'approved', 'availability' => 'available'],
        'reject' => ['user' => 'inactive', 'volunteer' => 'inactive', 'availability' => 'unavailable'],
    ];
    if (!isset($transitions[$decision])) {
        throw new InvalidArgumentException('Invalid registration decision');
    }

    $transition = $transitions[$decision];
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    try {
        $userQuery = $db->prepare('SELECT UserID, Username, FullName, Phone, Role, Status FROM Users WHERE UserID = :user_id');
        $userQuery->execute([':user_id' => $userId]);
        $pendingUser = $userQuery->fetch(PDO::FETCH_ASSOC);
        if (!$pendingUser || strtolower((string)$pendingUser['Status']) !== 'pending') {
            if ($ownsTransaction) {
                $db->rollBack();
            }
            return false;
        }

        $userUpdate = $db->prepare("UPDATE Users SET Status = :status, UpdatedAt = CURRENT_TIMESTAMP WHERE UserID = :user_id AND Status = 'pending'");
        $userUpdate->execute([':status' => $transition['user'], ':user_id' => $userId]);
        if ($userUpdate->rowCount() !== 1) {
            if ($ownsTransaction) {
                $db->rollBack();
            }
            return false;
        }

        if (strtolower((string)$pendingUser['Role']) === 'volunteer') {
            $volunteerQuery = $db->prepare('SELECT VolunteerID FROM Volunteers WHERE UserID = :user_id LIMIT 1');
            $volunteerQuery->execute([':user_id' => $userId]);
            $volunteer = $volunteerQuery->fetch(PDO::FETCH_ASSOC);

            if ($volunteer) {
                $volunteerUpdate = $db->prepare('UPDATE Volunteers SET Status = :status, AvailabilityStatus = :availability WHERE VolunteerID = :volunteer_id');
                $volunteerUpdate->execute([
                    ':status' => $transition['volunteer'],
                    ':availability' => $transition['availability'],
                    ':volunteer_id' => $volunteer['VolunteerID'],
                ]);
            } elseif ($decision === 'approve') {
                $fullName = trim((string)($pendingUser['FullName'] ?? ''));
                if ($fullName === '') {
                    $fullName = (string)$pendingUser['Username'];
                }
                $nameParts = preg_split('/\s+/', $fullName, 2);
                $volunteerInsert = $db->prepare('INSERT INTO Volunteers (UserID, FirstName, LastName, Phone, Address, AvailabilityStatus, Status) VALUES (:user_id, :first_name, :last_name, :phone, NULL, :availability, :status)');
                $volunteerInsert->execute([
                    ':user_id' => $userId,
                    ':first_name' => $nameParts[0] ?? $fullName,
                    ':last_name' => $nameParts[1] ?? '',
                    ':phone' => $pendingUser['Phone'],
                    ':availability' => $transition['availability'],
                    ':status' => $transition['volunteer'],
                ]);
            }
        }

        $activityLog = $db->prepare('INSERT INTO ActivityLog (UserID, Action, AffectedEntityName, AffectedEntityID, Details) VALUES (:actor_id, :action, :entity_name, :entity_id, :details)');
        $activityLog->execute([
            ':actor_id' => $actorUserId,
            ':action' => $decision === 'approve' ? 'approve_user' : 'reject_user',
            ':entity_name' => 'User',
            ':entity_id' => $userId,
            ':details' => $decision === 'approve' ? 'Approved user registration' : 'Rejected user registration',
        ]);

        if ($ownsTransaction) {
            $db->commit();
        }
        return ['status' => $transition['user']];
    } catch (Exception $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function handleUsers($method, $id, $subresource = null) {
    $user = requireAuth();
    $db = getDBConnection();
    $model = new User($db);

    if ($subresource !== null) {
        requireApiRole($user, ['admin']);
        if (!$id) {
            apiJsonResponse(false, 'User ID is required', null, 400);
        }
        if ($method !== 'POST') {
            apiJsonResponse(false, 'Method not allowed', null, 405);
        }
        if (!in_array($subresource, ['approve', 'reject'], true)) {
            apiJsonResponse(false, 'User action not found', null, 404);
        }

        $result = transitionPendingRegistration($db, (int)$id, $subresource, (int)$user['UserID']);
        if (!$result) {
            apiJsonResponse(false, 'User not found or registration is no longer pending', null, 409);
        }

        $message = $subresource === 'approve'
            ? 'User approved successfully. They can now log in.'
            : 'User registration rejected';
        apiJsonResponse(true, $message, [
            'UserID' => (int)$id,
            'Status' => $result['status'],
        ]);
    }

    switch ($method) {
        case 'GET':
            if ($id) {
                if ((int)$id !== (int)$user['UserID']) {
                    requireApiRole($user, ['admin']);
                }
                $data = $model->getUserById((int)$id);
                if (!$data) {
                    apiJsonResponse(false, 'User not found', null, 404);
                }
                unset($data['PasswordHash']);
                apiJsonResponse(true, 'User retrieved', $data);
            } else {
                requireApiRole($user, ['admin']);
                $stmt = $db->query("SELECT UserID, Username, Email, FullName, Phone, Role, Status, CreatedAt FROM Users ORDER BY CreatedAt DESC");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                apiJsonResponse(true, 'Users retrieved', $users);
            }
            break;

        case 'POST':
            requireApiRole($user, ['admin']);
            $input = getJsonInput();
            validateRequired($input, ['username', 'email', 'password']);

            $uid = $model->register(
                $input['username'],
                $input['email'],
                $input['password'],
                $input['role'] ?? 'volunteer',
                $input['full_name'] ?? null,
                $input['phone'] ?? null,
                $input['status'] ?? 'active'
            );
            if ($uid) {
                $data = $model->getUserById((int)$uid);
                unset($data['PasswordHash']);
                apiJsonResponse(true, 'User created successfully', $data, 201);
            }
            apiJsonResponse(false, 'Failed to create user', null, 500);
            break;

        case 'PUT':
            if (!$id) {
                apiJsonResponse(false, 'User ID is required', null, 400);
            }
            $input = getJsonInput();

            $isSelf = (int)$id === (int)$user['UserID'];
            if (!$isSelf) {
                requireApiRole($user, ['admin']);
            }

            if (isset($input['password'])) {
                if ($isSelf) {
                    $authUser = $model->getUserAuthById((int)$id);
                    if (!$authUser || empty($input['current_password']) || !password_verify($input['current_password'], $authUser['PasswordHash'])) {
                        apiJsonResponse(false, 'Current password is incorrect', null, 400);
                    }
                    if (($input['confirm_password'] ?? '') !== $input['password']) {
                        apiJsonResponse(false, 'New passwords do not match', null, 400);
                    }
                }
                $model->changePassword((int)$id, $input['password']);
                apiJsonResponse(true, 'Password changed successfully');
            } elseif (isset($input['email']) || isset($input['full_name']) || isset($input['phone']) || isset($input['role']) || isset($input['status'])) {
                $existing = $model->getUserById((int)$id);
                if (!$existing) {
                    apiJsonResponse(false, 'User not found', null, 404);
                }
                $allowedRoles = ['admin', 'staff', 'volunteer', 'donor'];
                $allowedStatuses = ['active', 'pending', 'inactive'];
                $role = $input['role'] ?? ($existing['Role'] ?? null);
                $status = $input['status'] ?? ($existing['Status'] ?? null);
                if (!$isSelf && !in_array($role, $allowedRoles, true)) {
                    apiJsonResponse(false, 'Invalid user role', null, 400);
                }
                if (!$isSelf && !in_array($status, $allowedStatuses, true)) {
                    apiJsonResponse(false, 'Invalid user status', null, 400);
                }
                $model->updateDetails(
                    (int)$id,
                    $input['email'] ?? $existing['Email'],
                    $input['full_name'] ?? $existing['FullName'],
                    $input['phone'] ?? $existing['Phone'],
                    $isSelf ? null : $role,
                    $isSelf ? null : $status
                );
                apiJsonResponse(true, 'User updated successfully', $model->getUserById((int)$id));
            }
            apiJsonResponse(false, 'Invalid update request', null, 400);
            break;

        default:
            apiJsonResponse(false, 'Method not allowed', null, 405);
    }
}
