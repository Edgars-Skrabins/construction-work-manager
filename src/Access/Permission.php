<?php

declare(strict_types=1);

namespace App\Access;

enum Permission: string
{
    case EmployeeCreate = 'employee.create';
    case EmployeeRead = 'employee.read';
    case EmployeeUpdate = 'employee.update';
    case EmployeeDelete = 'employee.delete';
    case SiteCreate = 'site.create';
    case SiteRead = 'site.read';
    case SiteUpdate = 'site.update';
    case SiteDelete = 'site.delete';
    case WorkItemCreate = 'workItem.create';
    case WorkItemRead = 'workItem.read';
    case WorkItemUpdate = 'workItem.update';
    case WorkItemDelete = 'workItem.delete';
    case AssignmentCreate = 'assignment.create';
    case AssignmentRead = 'assignment.read';
    case AssignmentReadOwn = 'assignment.readOwn';
    case AssignmentUpdate = 'assignment.update';
    case AssignmentDelete = 'assignment.delete';
}
